<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects declarations and references from an AST that NameResolver has already
 * visited, so class names are fully qualified. Edge kinds follow spec/languages/php.md.
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    private ?string $module = null;
    private bool $sawNamespace = false;

    /** @var list<string> */
    private array $classes = [];
    /** @var list<string> */
    private array $functions = [];
    /** @var list<string> */
    private array $constants = [];
    /** @var list<array{symbol: string, candidates: list<string>, kind: string}> */
    private array $references = [];
    /** @var list<array{path: ?string, text: string}> */
    private array $includes = [];

    /** Parent class of each enclosing class-like, to resolve `parent`. @var list<?string> */
    private array $parents = [];

    public function __construct(
        private readonly string $code,
        private readonly string $absolutePath,
    ) {
    }

    public function result(): FileResult
    {
        return new FileResult($this->module, $this->classes, $this->functions, $this->constants, $this->references, $this->includes);
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_ && !$this->sawNamespace) {
            $this->sawNamespace = true;
            $this->module = $node->name?->toString();
        }

        if ($node instanceof Stmt\ClassLike) {
            $this->enterClassLike($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->functions[] = $node->namespacedName->toString();
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $const) {
                $this->constants[] = $const->namespacedName->toString();
            }
        } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $this->addImports($node);
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addClass($trait, 'trait');
            }
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addClass($type, 'catch');
            }
        } elseif ($node instanceof Stmt\Property || $node instanceof Stmt\ClassConst) {
            $this->addType($node->type);
        } elseif ($node instanceof Node\Attribute) {
            $this->addClass($node->name, 'attribute');
        } elseif ($node instanceof Expr\New_) {
            $this->addClassExpr($node->class, 'new');
        } elseif ($node instanceof Expr\StaticCall || $node instanceof Expr\ClassConstFetch || $node instanceof Expr\StaticPropertyFetch) {
            // `$object::class` names no class to load, so it is not a dynamic reference.
            if (!($node instanceof Expr\ClassConstFetch && $node->class instanceof Expr && $node->name instanceof Node\Identifier
                && $node->name->toLowerString() === 'class')) {
                $this->addClassExpr($node->class, 'static');
            }
        } elseif ($node instanceof Expr\Instanceof_) {
            $this->addClassExpr($node->class, 'instanceof');
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->addFunctionCall($node);
        } elseif ($node instanceof Expr\ConstFetch) {
            if (!in_array($node->name->toLowerString(), ['true', 'false', 'null'], true)) {
                $this->references[] = ['symbol' => FileResult::CONSTANT_SYMBOL, 'candidates' => self::candidates($node->name), 'kind' => 'const'];
            }
        } elseif ($node instanceof Expr\Include_) {
            $this->includes[] = [
                'path' => $this->evaluatePath($node->expr),
                'text' => $this->sourceText($node->expr),
            ];
        }

        if ($node instanceof Node\FunctionLike) {
            foreach ($node->getParams() as $param) {
                $this->addType($param->type);
            }
            $this->addType($node->getReturnType());
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->parents);
        }
        return null;
    }

    private function enterClassLike(Stmt\ClassLike $node): void
    {
        // Anonymous classes have no name but still extend and implement.
        if ($node->namespacedName !== null) {
            $this->classes[] = $node->namespacedName->toString();
        }
        $parent = null;
        if ($node instanceof Stmt\Class_) {
            if ($node->extends !== null) {
                $parent = $node->extends->toString();
                $this->addClass($node->extends, 'extends');
            }
            foreach ($node->implements as $interface) {
                $this->addClass($interface, 'implements');
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addClass($interface, 'extends');
            }
        } elseif ($node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addClass($interface, 'implements');
            }
        }
        $this->parents[] = $parent;
    }

    private function addImports(Stmt\Use_|Stmt\GroupUse $node): void
    {
        foreach ($node->uses as $use) {
            $type = $use->type !== Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type;
            $name = $node instanceof Stmt\GroupUse ? Name::concat($node->prefix, $use->name)->toString() : $use->name->toString();
            $symbol = match ($type) {
                Stmt\Use_::TYPE_FUNCTION => FileResult::FUNCTION_SYMBOL,
                Stmt\Use_::TYPE_CONSTANT => FileResult::CONSTANT_SYMBOL,
                default => FileResult::CLASS_SYMBOL,
            };
            $this->references[] = ['symbol' => $symbol, 'candidates' => [$name], 'kind' => 'import'];
        }
    }

    private function addClass(Name $name, string $kind): void
    {
        if ($name->isSpecialClassName()) {
            // self and static point at the current class (a self-edge); parent is its parent class.
            $parent = $this->parents === [] ? null : end($this->parents);
            if ($name->toLowerString() !== 'parent' || $parent === null) {
                return;
            }
            $resolved = $parent;
        } else {
            $resolved = $name->toString();
        }
        $this->references[] = ['symbol' => FileResult::CLASS_SYMBOL, 'candidates' => [$resolved], 'kind' => $kind];
    }

    /**
     * A class named in code: a Name is resolved, an expression becomes a dynamic reference.
     * `$this::X` is the enclosing class, like `static::X`, so it names nothing to resolve.
     */
    private function addClassExpr(Node $class, string $kind): void
    {
        if ($class instanceof Name) {
            $this->addClass($class, $kind);
        } elseif ($class instanceof Expr\Variable && $class->name === 'this') {
            return;
        } elseif ($class instanceof Expr) {
            $this->references[] = ['symbol' => FileResult::DYNAMIC_SYMBOL, 'candidates' => [$this->sourceText($class)], 'kind' => $kind];
        }
    }

    private function addType(?Node $type): void
    {
        if ($type instanceof Name) {
            $this->addClass($type, 'type');
        } elseif ($type instanceof Node\NullableType) {
            $this->addType($type->type);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $inner) {
                $this->addType($inner);
            }
        }
    }

    private function addFunctionCall(Expr\FuncCall $node): void
    {
        $name = $node->name;
        assert($name instanceof Name);
        // define('NAME', ...) declares a global constant.
        if ($name->toLowerString() === 'define' && isset($node->args[0]) && $node->args[0] instanceof Node\Arg
            && $node->args[0]->value instanceof Scalar\String_) {
            $this->constants[] = ltrim($node->args[0]->value->value, '\\');
        }
        $this->references[] = ['symbol' => FileResult::FUNCTION_SYMBOL, 'candidates' => self::candidates($name), 'kind' => 'call'];
    }

    /**
     * An unqualified function or constant inside a namespace may mean the namespaced
     * name or, through PHP's fallback, the global one. Try them in that order.
     *
     * @return list<string>
     */
    private static function candidates(Name $name): array
    {
        $namespaced = $name->getAttribute('namespacedName');
        if ($namespaced instanceof Name) {
            return [$namespaced->toString(), $name->toString()];
        }
        return [$name->toString()];
    }

    /**
     * Evaluates an include path built only from string literals, __DIR__, __FILE__,
     * dirname() and the '.' operator. Returns null for anything else.
     */
    private function evaluatePath(Expr $expr): ?string
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\MagicConst\Dir) {
            return dirname($this->absolutePath);
        }
        if ($expr instanceof Scalar\MagicConst\File) {
            return $this->absolutePath;
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->evaluatePath($expr->left);
            $right = $left === null ? null : $this->evaluatePath($expr->right);
            return $right === null ? null : $left . $right;
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name && $expr->name->toLowerString() === 'dirname'
            && isset($expr->args[0]) && $expr->args[0] instanceof Node\Arg) {
            $path = $this->evaluatePath($expr->args[0]->value);
            if ($path === null) {
                return null;
            }
            $levels = 1;
            if (isset($expr->args[1])) {
                if (!$expr->args[1] instanceof Node\Arg || !$expr->args[1]->value instanceof Scalar\Int_) {
                    return null;
                }
                $levels = max(1, $expr->args[1]->value->value);
            }
            return dirname($path, $levels);
        }
        return null;
    }

    private function sourceText(Expr $expr): string
    {
        $start = $expr->getStartFilePos();
        $end = $expr->getEndFilePos();
        if ($start < 0 || $end < $start) {
            return '?';
        }
        return preg_replace('/\s+/', ' ', substr($this->code, $start, $end - $start + 1)) ?? '?';
    }
}

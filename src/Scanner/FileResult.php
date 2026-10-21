<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use Untangled\Text;

/** What one PHP file declares and references, before resolution. */
final class FileResult
{
    public const CLASS_SYMBOL = 'class';
    public const FUNCTION_SYMBOL = 'function';
    public const CONSTANT_SYMBOL = 'constant';

    /**
     * @param list<string> $classes fully qualified class-like names declared in the file
     * @param list<string> $functions fully qualified function names declared in the file
     * @param list<string> $constants fully qualified constant names declared in the file
     * @param list<array{symbol: string, candidates: list<string>, kind: string}> $references
     *        candidates are tried in order (namespaced name first, then the global fallback)
     * @param list<array{path: ?string, text: string}> $includes path is the evaluated
     *        literal path, or null when the expression is not constant
     */
    public function __construct(
        public readonly ?string $module,
        public readonly array $classes,
        public readonly array $functions,
        public readonly array $constants,
        public readonly array $references,
        public readonly array $includes,
        public readonly ?string $error = null,
        /** With $error, the pairs document's `error` for the file: "parse" or "read". */
        public readonly ?string $unread = null,
    ) {
    }

    public static function failed(string $error, string $unread): self
    {
        return new self(null, [], [], [], [], [], $error, $unread);
    }

    /** @return list<string> */
    public function symbols(): array
    {
        $symbols = array_values(array_unique(array_map(Text::utf8(...), array_merge($this->classes, $this->functions, $this->constants))));
        sort($symbols, SORT_STRING);
        return $symbols;
    }
}

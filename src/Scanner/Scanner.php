<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use Untangled\Composer\ComposerProject;
use Untangled\Graph\PairsDocument;
use Untangled\Path;

/**
 * Scans a Composer project into a pairs document, following spec/languages/php.md:
 * autoload rules choose the files, literal includes are followed, and every
 * reference resolves to a project file, a Composer package, a builtin, or
 * an unresolved node.
 */
final class Scanner
{
    public const NOTHING_SCANNED = 'no PHP files were found to scan: composer.json autoloads none and no --source folder'
        . ' holds any, so there is nothing to analyze';

    private readonly FileScanner $fileScanner;

    /** Classes Composer writes into vendor/composer/ itself, provided by its composer-runtime-api. */
    private const COMPOSER_RUNTIME = ['composer\\autoload\\classloader', 'composer\\installedversions'];
    private const COMPOSER_RUNTIME_PACKAGE = 'composer-runtime-api';

    /** How many folders the warning about files outside autoload names. */
    private const UNCOVERED_FOLDERS = 5;

    /**
     * @param list<string> $sources extra project-relative files or folders to scan, read like
     *        classmap entries, for code that composer.json does not autoload
     * @param ClassAliases|null $aliases global class aliases registered at runtime (`--aliases`)
     */
    public function __construct(
        private readonly bool $dev = false,
        private readonly array $sources = [],
        private readonly ?ClassAliases $aliases = null,
    ) {
        $this->fileScanner = new FileScanner();
    }

    public function scan(string $path): ScanResult
    {
        $project = ComposerProject::load($path);
        $root = $project->root;
        $vendorDir = $project->vendorDir();
        $warnings = [];

        // Scan the autoloaded files, then any project file reached through a literal include.
        /** @var array<string, FileResult> $results */
        $results = [];
        /** @var array<string, list<string>> include targets per file, as node ids */
        $includeTargets = [];
        foreach ($this->sources as $source) {
            if (!file_exists($root . '/' . Path::normalize($source))) {
                $warnings[] = "--source={$source}: no such file or folder in the project";
            }
        }
        $queue = $project->sourceFiles($this->dev, $this->sources);
        for ($next = 0; $next < count($queue); $next++) {
            $file = $queue[$next];
            if (isset($results[$file])) {
                continue;
            }
            $result = $this->fileScanner->scan($root . '/' . $file);
            $results[$file] = $result;
            if ($result->error !== null) {
                $warnings[] = "{$file}: {$result->error}";
            }
            foreach ($result->includes as $include) {
                $target = $this->resolveInclude($include, $root, $file, $vendorDir);
                if ($target === null) {
                    continue;
                }
                $includeTargets[$file][] = $target;
                if (!self::isPrefixedId($target) && !isset($results[$target])) {
                    $queue[] = $target;
                }
            }
        }
        ksort($results, SORT_STRING);
        if ($results === []) {
            $warnings[] = self::NOTHING_SCANNED;
        }

        $packageListError = $project->packageListError();
        if ($packageListError !== null) {
            $warnings[] = "{$packageListError}, so classes from third-party packages cannot be traced and show as"
                . ' unresolved; run `composer install` again for a complete picture';
        } elseif (!$project->hasPackageList() && $project->requiresPackages()) {
            $warnings[] = 'no vendor/composer/installed.json or composer.lock, so classes from third-party packages'
                . ' cannot be traced and show as unresolved; run `composer install` first for a complete picture';
        }
        $uncovered = $project->uncoveredFiles(array_fill_keys(array_merge(array_keys($results), $project->sourceFiles(true, $this->sources)), true));
        if ($uncovered !== []) {
            $warnings[] = self::uncoveredWarning($uncovered);
        }

        // Symbol tables. Class and function names are case-insensitive in PHP, constants are not.
        $classes = [];
        $functions = [];
        $constants = [];
        foreach ($results as $file => $result) {
            foreach ($result->classes as $class) {
                $key = strtolower($class);
                if (isset($classes[$key])) {
                    // Declaring a class twice in one file (inside if/else) is a common polyfill pattern.
                    if ($classes[$key] !== $file) {
                        $warnings[] = "{$file}: {$class} is also declared in {$classes[$key]}; using the first";
                    }
                    continue;
                }
                $classes[$key] = $file;
            }
            foreach ($result->functions as $function) {
                $functions[strtolower($function)] ??= $file;
            }
            foreach ($result->constants as $constant) {
                $constants[$constant] ??= $file;
            }
        }

        $packages = $project->packages();
        $vendor = new VendorIndex($packages, $this->fileScanner);
        $aliases = ($this->aliases ?? ClassAliases::none())->withPackages($packages);
        $pairs = new PairsDocument('php');

        foreach ($results as $file => $result) {
            $extra = [];
            if ($result->module !== null) {
                $extra['module'] = $result->module;
            }
            $symbols = $result->symbols();
            if ($symbols !== []) {
                $extra['symbols'] = $symbols;
            }
            if ($result->unread !== null) {
                $extra['error'] = $result->unread;
            }
            $pairs->addNode($file, PairsDocument::INTERNAL, $extra);
        }

        /** @var array<string, string> $resolved class name => target, as the same names recur in every file */
        $resolved = [];
        foreach ($results as $file => $result) {
            foreach ($result->references as $reference) {
                $target = match ($reference['symbol']) {
                    FileResult::CLASS_SYMBOL => $resolved[$reference['candidates'][0]] ??= $this->resolveAlias($reference['candidates'][0], $classes, $vendor, $aliases),
                    FileResult::FUNCTION_SYMBOL => self::firstMatch($reference['candidates'], $functions, true),
                    default => self::firstMatch($reference['candidates'], $constants, false),
                };
                if ($target === null) {
                    continue;
                }
                // An import that resolves to nothing may name a namespace (`use App\Models;`);
                // the code that uses it is resolved on its own, so the import adds nothing.
                if ($reference['kind'] === 'import' && str_starts_with($target, 'unresolved:')) {
                    continue;
                }
                $this->link($pairs, $file, $target, $reference['kind']);
            }
            foreach ($includeTargets[$file] ?? [] as $target) {
                $this->link($pairs, $file, $target, 'include');
            }
        }

        return new ScanResult($pairs, $warnings);
    }

    /** @param non-empty-list<string> $files */
    private static function uncoveredWarning(array $files): string
    {
        // One entry per top-level folder, named by the deepest folder its files share
        // (database/migrations/ rather than database/, lang/ rather than every lang/xx/).
        $groups = [];
        foreach ($files as $file) {
            $directories = array_slice(explode('/', $file), 0, -1);
            $groups[$directories[0] ?? ''][] = $directories;
        }
        $folders = [];
        foreach ($groups as $top => $paths) {
            $common = $paths[0];
            foreach ($paths as $path) {
                $length = 0;
                while ($length < count($common) && $length < count($path) && $common[$length] === $path[$length]) {
                    $length++;
                }
                $common = array_slice($common, 0, $length);
            }
            $folders[$top === '' ? '(project root)' : implode('/', $common) . '/'] = count($paths);
        }
        uksort($folders, static fn (string $a, string $b): int => [$folders[$b], $a] <=> [$folders[$a], $b]);
        $named = [];
        foreach (array_slice($folders, 0, self::UNCOVERED_FOLDERS, true) as $folder => $count) {
            $named[] = "{$folder} {$count}";
        }
        if (count($folders) > self::UNCOVERED_FOLDERS) {
            $named[] = 'and ' . (count($folders) - self::UNCOVERED_FOLDERS) . ' more';
        }
        $count = count($files);
        return ($count === 1 ? '1 PHP file is' : "{$count} PHP files are") . ' not covered by composer.json autoload rules'
            . ($count === 1 ? ' and was' : ' and were') . ' not scanned (' . implode(', ', $named) . '); add folders with --source=<folder>'
            . ' if they hold code you want analyzed';
    }

    private static function isPrefixedId(string $id): bool
    {
        return str_starts_with($id, 'composer:') || str_starts_with($id, 'builtin:') || str_starts_with($id, 'unresolved:');
    }

    private function link(PairsDocument $pairs, string $from, string $to, string $kind): void
    {
        if (!$pairs->hasNode($to)) {
            $nodeKind = match (true) {
                str_starts_with($to, 'composer:') => PairsDocument::EXTERNAL,
                str_starts_with($to, 'builtin:') => PairsDocument::BUILTIN,
                str_starts_with($to, 'unresolved:') => PairsDocument::UNRESOLVED,
                default => PairsDocument::INTERNAL,
            };
            $pairs->addNode($to, $nodeKind);
        }
        $pairs->addEdge($from, $to, $kind);
    }

    /**
     * Resolves a class name, then, if nothing declares it, the class it is a declared alias of.
     * The alias target is resolved the same way but not followed further.
     *
     * @param array<string, string> $classes
     */
    private function resolveAlias(string $name, array $classes, VendorIndex $vendor, ClassAliases $aliases): string
    {
        $target = $this->resolveClass($name, $classes, $vendor);
        $alias = $aliases->target($name);
        return $alias !== null && str_starts_with($target, 'unresolved:') ? $this->resolveClass($alias, $classes, $vendor) : $target;
    }

    /** @param array<string, string> $classes */
    private function resolveClass(string $name, array $classes, VendorIndex $vendor): string
    {
        if (isset($classes[strtolower($name)])) {
            return $classes[strtolower($name)];
        }
        // Builtins before packages: polyfills (symfony/polyfill-php80 and the like) declare
        // classes such as Stringable that the running PHP already has.
        $builtin = self::builtinClass($name);
        if ($builtin !== null) {
            return 'builtin:' . $builtin;
        }
        $package = $vendor->packageFor($name);
        if ($package !== null) {
            return 'composer:' . $package;
        }
        if (in_array(strtolower($name), self::COMPOSER_RUNTIME, true)) {
            return 'composer:' . self::COMPOSER_RUNTIME_PACKAGE;
        }
        return 'unresolved:class:' . $name;
    }

    /**
     * Functions and constants only count when the project declares them; anything
     * else is taken to be builtin and not recorded (spec/languages/php.md).
     *
     * @param list<string> $candidates
     * @param array<string, string> $table
     */
    private static function firstMatch(array $candidates, array $table, bool $caseInsensitive): ?string
    {
        foreach ($candidates as $candidate) {
            $key = $caseInsensitive ? strtolower($candidate) : $candidate;
            if (isset($table[$key])) {
                return $table[$key];
            }
        }
        return null;
    }

    /** The canonical name of a class-like built into the running PHP, or null. */
    private static function builtinClass(string $name): ?string
    {
        if (!class_exists($name, false) && !interface_exists($name, false) && !trait_exists($name, false) && !enum_exists($name, false)) {
            return null;
        }
        $reflection = new \ReflectionClass($name);
        return $reflection->isInternal() ? $reflection->getName() : null;
    }

    /**
     * Turns an include into a node id: a project file (which is then scanned too),
     * a Composer package, or an unresolved include. Null for Composer's own autoloader,
     * which only loads the packages that class references already point at.
     *
     * @param array{path: ?string, text: string} $include
     */
    private function resolveInclude(array $include, string $root, string $file, string $vendorDir): ?string
    {
        if ($include['path'] === null) {
            return 'unresolved:include:' . $include['text'];
        }
        $path = $include['path'];
        $isAbsolute = str_starts_with(str_replace('\\', '/', $path), '/');
        // A relative path is tried against the project root (PHP's working directory
        // when run from the root), then against the including file's directory, then
        // against each --source folder (legacy applications put those on include_path).
        $candidates = [Path::normalize($path)];
        if (!$isAbsolute) {
            $candidates = [Path::join($root, $path), Path::join(dirname($root . '/' . $file), $path)];
            foreach ($this->sources as $source) {
                $candidates[] = Path::join($root . '/' . Path::normalize($source), $path);
            }
        }

        foreach ($candidates as $candidate) {
            $relative = Path::relative($root, $candidate);
            if ($relative === null || !is_file($candidate)) {
                continue;
            }
            if (str_starts_with($relative, $vendorDir . '/')) {
                $parts = explode('/', substr($relative, strlen($vendorDir) + 1));
                if ($parts === ['autoload.php'] || $parts[0] === 'composer') {
                    return null;
                }
                if (count($parts) >= 3) {
                    return 'composer:' . $parts[0] . '/' . $parts[1];
                }
                continue;
            }
            return $relative;
        }

        $relative = Path::relative($root, $candidates[0]);
        return 'unresolved:include:' . ($relative ?? $include['text']);
    }
}

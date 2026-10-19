<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use Untangled\Composer\Package;

/**
 * Global class aliases the user declares (`DB` for Illuminate\Support\Facades\DB), for
 * names a framework registers at runtime with class_alias() rather than through Composer.
 * They come from `--aliases=<preset|file>`: a preset shipped in resources/aliases/, or a
 * JSON file of the same shape (spec/languages/php.md).
 */
final class ClassAliases
{
    private const PRESETS = __DIR__ . '/../../resources/aliases';
    private const CLASS_NAME = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    /**
     * @param array<string, string> $aliases lowercased alias => class
     * @param list<list<string>> $packageExtra paths under a package's `extra` that hold more aliases
     */
    private function __construct(
        private readonly array $aliases = [],
        private readonly array $packageExtra = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /** @return list<string> the preset names, from resources/aliases/<name>.json */
    public static function presets(): array
    {
        return array_map(static fn (string $file): string => basename($file, '.json'), glob(self::PRESETS . '/*.json') ?: []);
    }

    /**
     * @param list<string> $specs preset names or JSON files, in command-line order; a later one wins
     * @throws \InvalidArgumentException when a file cannot be read or is not an alias file
     */
    public static function load(array $specs): self
    {
        $aliases = [];
        $packageExtra = [];
        foreach ($specs as $spec) {
            $file = in_array($spec, self::presets(), true) ? self::PRESETS . "/{$spec}.json" : $spec;
            $contents = is_file($file) ? file_get_contents($file) : false;
            if ($contents === false) {
                throw new \InvalidArgumentException("--aliases={$spec}: not a preset (" . implode(', ', self::presets()) . ') or a readable file');
            }
            $data = json_decode($contents, true);
            $declared = is_array($data) ? ($data['aliases'] ?? null) : null;
            if (!is_array($declared)) {
                throw new \InvalidArgumentException("--aliases={$spec}: expected a JSON object with an \"aliases\" object");
            }
            foreach ($declared as $alias => $class) {
                if (!is_string($class) || !preg_match(self::CLASS_NAME, (string) $alias) || !preg_match(self::CLASS_NAME, $class)) {
                    throw new \InvalidArgumentException("--aliases={$spec}: \"{$alias}\" must map a class name to a class name");
                }
                $aliases[strtolower(ltrim((string) $alias, '\\'))] = ltrim($class, '\\');
            }
            if (is_string($data['package-extra'] ?? null)) {
                $packageExtra[] = explode('.', $data['package-extra']);
            }
        }
        return new self($aliases, $packageExtra);
    }

    /**
     * Adds the aliases installed packages declare (Laravel packages list theirs under
     * extra.laravel.aliases), for the presets that name such a path. Declared aliases win.
     *
     * @param list<Package> $packages
     */
    public function withPackages(array $packages): self
    {
        $fromPackages = [];
        foreach ($this->packageExtra as $path) {
            foreach ($packages as $package) {
                $value = $package->extra;
                foreach ($path as $key) {
                    $value = is_array($value) ? ($value[$key] ?? null) : null;
                }
                foreach (is_array($value) ? $value : [] as $alias => $class) {
                    if (is_string($class) && preg_match(self::CLASS_NAME, (string) $alias) && preg_match(self::CLASS_NAME, $class)) {
                        $fromPackages[strtolower(ltrim((string) $alias, '\\'))] ??= ltrim($class, '\\');
                    }
                }
            }
        }
        return new self($this->aliases + $fromPackages);
    }

    /** The class a fully qualified name is an alias of, or null. Names compare case-insensitively. */
    public function target(string $name): ?string
    {
        return $this->aliases[strtolower(ltrim($name, '\\'))] ?? null;
    }
}

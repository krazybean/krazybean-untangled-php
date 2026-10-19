<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use Untangled\Composer\Package;

/** Maps class names to the installed Composer package that provides them. */
final class VendorIndex
{
    /** Lowercased prefix => package, longest prefix first. @var array<string, string> */
    private array $prefixes = [];

    /** Lowercased class name => package, built lazily from classmap packages. @var array<string, string>|null */
    private ?array $classmap = null;

    /** @param list<Package> $packages */
    public function __construct(
        private readonly array $packages,
        private readonly FileScanner $fileScanner,
    ) {
        foreach ($packages as $package) {
            foreach ($package->prefixes() as $prefix) {
                $this->prefixes[strtolower($prefix)] ??= $package->name;
            }
        }
        uksort($this->prefixes, static fn (string $a, string $b): int => [strlen($b), $a] <=> [strlen($a), $b]);
    }

    public function packageFor(string $class): ?string
    {
        $key = strtolower($class);
        $classmap = $this->classmap();
        if (isset($classmap[$key])) {
            return $classmap[$key];
        }
        foreach ($this->prefixes as $prefix => $package) {
            // psr-4 prefixes end in '\\'; psr-0 ones may end in '_' or in neither.
            if (str_ends_with($prefix, '\\') || str_ends_with($prefix, '_')) {
                if (str_starts_with($key, $prefix)) {
                    return $package;
                }
            } elseif ($key === $prefix || str_starts_with($key, $prefix . '\\') || str_starts_with($key, $prefix . '_')) {
                return $package;
            }
        }
        return null;
    }

    /**
     * Classes of packages that autoload through a classmap. Only available when vendor/
     * is installed, since the classes are read from the package's files.
     *
     * @return array<string, string>
     */
    private function classmap(): array
    {
        if ($this->classmap !== null) {
            return $this->classmap;
        }
        $this->classmap = [];
        foreach ($this->packages as $package) {
            if ($package->installPath === null) {
                continue;
            }
            foreach ($package->classmap() as $entry) {
                foreach (self::phpFiles($package->installPath . '/' . $entry) as $file) {
                    foreach ($this->fileScanner->scan($file)->classes as $class) {
                        $this->classmap[strtolower($class)] ??= $package->name;
                    }
                }
            }
        }
        return $this->classmap;
    }

    /** @return list<string> */
    private static function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'inc'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }
}

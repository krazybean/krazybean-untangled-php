<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Composer;

/** An installed third-party package and its autoload rules. */
final class Package
{
    /**
     * @param array<string, mixed> $autoload
     * @param string|null $installPath absolute path when vendor/ is installed, null when read from composer.lock
     * @param array<string, mixed> $extra the package's `extra` section
     */
    public function __construct(
        public readonly string $name,
        public readonly array $autoload,
        public readonly ?string $installPath,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Namespace prefixes from psr-4 and psr-0, as written (`Monolog\\`, `Twig_`).
     * Empty (catch-all) prefixes are ignored: they would claim every unknown class.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        $prefixes = [];
        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach (array_keys((array) ($this->autoload[$standard] ?? [])) as $prefix) {
                $prefix = ltrim((string) $prefix, '\\');
                if ($prefix !== '') {
                    $prefixes[] = $prefix;
                }
            }
        }
        return $prefixes;
    }

    /** @return list<string> */
    public function classmap(): array
    {
        return array_map('strval', (array) ($this->autoload['classmap'] ?? []));
    }
}

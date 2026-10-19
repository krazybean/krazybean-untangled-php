<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled;

/** Lexical path helpers: no filesystem access, no symlink resolution. */
final class Path
{
    /** Collapses '.', '..' and duplicate separators. Keeps a leading '/' if present. */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif (!$absolute) {
                    $parts[] = '..';
                }
                continue;
            }
            $parts[] = $part;
        }
        return ($absolute ? '/' : '') . implode('/', $parts);
    }

    public static function join(string $base, string $path): string
    {
        return str_starts_with(str_replace('\\', '/', $path), '/') ? self::normalize($path) : self::normalize($base . '/' . $path);
    }

    /** The path of $absolute relative to $root, or null when it is outside $root. */
    public static function relative(string $root, string $absolute): ?string
    {
        $root = rtrim(self::normalize($root), '/');
        $absolute = self::normalize($absolute);
        if ($absolute === $root) {
            return '.';
        }
        return str_starts_with($absolute, $root . '/') ? substr($absolute, strlen($root) + 1) : null;
    }
}

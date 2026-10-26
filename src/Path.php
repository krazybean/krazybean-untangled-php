<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled;

/** Lexical path helpers: no filesystem access, no symlink resolution. */
final class Path
{
    /** True for a path that starts at a root: '/x', and on Windows 'C:/x' or 'C:\\x'. */
    public static function isAbsolute(string $path): bool
    {
        return preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $path) === 1;
    }

    /**
     * Collapses '.', '..' and duplicate separators, and writes every separator as '/'. Keeps a
     * leading '/' if present, and a Windows drive ('C:/') with its letter in upper case.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $drive = '';
        if (preg_match('~^([A-Za-z]):(?:/|$)~', $path, $match) === 1) {
            $drive = strtoupper($match[1]) . ':';
            $path = '/' . substr($path, 2);
        }
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
        return $drive . ($absolute ? '/' : '') . implode('/', $parts);
    }

    public static function join(string $base, string $path): string
    {
        return self::isAbsolute($path) ? self::normalize($path) : self::normalize($base . '/' . $path);
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

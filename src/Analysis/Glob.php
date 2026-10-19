<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

/**
 * Glob patterns on internal ids (spec/clustering.md): `*` and `?` stay within one path
 * segment, `**` matches across `/`, and `**` followed by `/` also matches no directory at all.
 */
final class Glob
{
    private readonly string $regex;

    public function __construct(public readonly string $pattern)
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $i++;
                if (($pattern[$i + 1] ?? '') === '/') {
                    $i++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }
        $this->regex = '#\A' . $regex . '\z#s';
    }

    public function matches(string $id): bool
    {
        return preg_match($this->regex, $id) === 1;
    }
}

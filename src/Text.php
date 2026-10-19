<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled;

/** Text helpers for names and paths read from source code and the filesystem. */
final class Text
{
    /** Windows-1252 bytes 0x80-0x9F that differ from Latin-1; the five undefined ones stay as Latin-1. */
    private const CP1252 = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
        0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
        0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /**
     * Documents are UTF-8, but PHP names and file names are bytes: legacy code saved as
     * Latin-1 can declare `class Café`. A string that is not valid UTF-8 is read as
     * Windows-1252, the encoding such code was almost always written in (spec/pairs-format.md).
     */
    public static function utf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        $utf8 = '';
        foreach (unpack('C*', $text) ?: [] as $byte) {
            $utf8 .= $byte < 0x80 ? chr($byte) : self::encode(self::CP1252[$byte] ?? $byte);
        }
        return $utf8;
    }

    private static function encode(int $codePoint): string
    {
        return $codePoint < 0x800
            ? chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F))
            : chr(0xE0 | ($codePoint >> 12)) . chr(0x80 | (($codePoint >> 6) & 0x3F)) . chr(0x80 | ($codePoint & 0x3F));
    }
}

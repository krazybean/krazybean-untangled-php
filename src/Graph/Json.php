<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Graph;

/**
 * Writes documents the way the spec asks: two-space indentation, a trailing newline,
 * no escaped slashes, and fractions with at most 4 decimal places.
 */
final class Json
{
    /** @param array<string, mixed> $document */
    public static function encode(array $document): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // json_encode indents by four spaces; the spec asks for two.
        $json = preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
        return $json . "\n";
    }

    /** Rounds to 4 decimal places; whole numbers become integers so they are written without ".0". */
    public static function number(float|int $value): float|int
    {
        $rounded = round((float) $value, 4);
        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    /**
     * @return array<string, mixed>
     * @throws DocumentException
     */
    public static function decode(string $json, string $what): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DocumentException("{$what} is not valid JSON: {$e->getMessage()}");
        }
        if (!is_array($data)) {
            throw new DocumentException("{$what} is not a JSON object");
        }
        return $data;
    }
}

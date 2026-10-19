<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Report;

use Untangled\Graph\PairsDocument;
use Untangled\Version;

/**
 * Renders a report as one self-contained HTML page (spec/report.md): the summary, plain-language
 * explanations, an interactive map of the unit graph, cycles, hotspots and the full metrics table.
 * Styles, script and data are inlined, so the file can be emailed or opened offline.
 */
final class HtmlReport
{
    /** The plain-language lines of spec/metrics.md, which reports must use verbatim. */
    public const PLAIN = [
        'fan_out' => 'How many other parts of the code this one relies on.',
        'fan_in' => 'How many other parts of the code rely on this one.',
        'external' => 'How many third-party packages this part uses directly.',
        'unresolved' => 'References we could not trace; the real coupling may be higher.',
        'instability' => 'How much this part is pushed around by changes elsewhere, from 0 (others lean on it) to 1 (it leans on others).',
        'ca' => 'How many files outside this group rely on it.',
        'ce' => 'How many files outside this group it relies on.',
        'cycle' => 'These parts all depend on each other, so none of them can be changed, tested or reused alone.',
    ];

    private const RESOURCES = __DIR__ . '/../../resources/report';

    /** How many units the hotspot lists show. */
    private const TOP = 5;

    /** How many units the map draws at most; beyond this a force layout is a slow, unreadable hairball. */
    public const MAP_LIMIT = 300;

    /** How many packages and untraced references are listed before the rest fold away. */
    public const LIST_LIMIT = 15;

    public static function render(Report $report, ?\DateTimeInterface $generatedAt = null): string
    {
        $analysis = $report->analysis;
        $units = $analysis['units'];
        $cycleOf = $report->cycleOf();
        $drawn = $report->mapUnits(self::MAP_LIMIT);

        $data = [
            'clustered' => $report->isClustered(),
            'plain' => self::PLAIN,
            'units' => array_map(static fn (array $unit): array => $unit + ['cycle' => $cycleOf[$unit['id']] ?? null], $units),
            'edges' => $report->unitEdges(),
            'cycles' => $analysis['cycles'],
            'drawn' => count($drawn) < count($units) ? $drawn : null,
            'dot' => Dot::render($report),
        ];

        $view = [
            'title' => $report->title,
            'generatedAt' => ($generatedAt ?? new \DateTimeImmutable())->format('j F Y'),
            'generator' => Version::NAME . ' ' . Version::VERSION,
            'clustered' => $report->isClustered(),
            'grouping' => self::describeGrouping($analysis['clustering'], $analysis['language']),
            'summary' => $analysis['summary'],
            'units' => $units,
            'cycles' => $analysis['cycles'],
            'cycleOf' => $cycleOf,
            'drawnCount' => count($drawn),
            'reliedOn' => self::top($units, 'fan_in'),
            'leaning' => self::top($units, 'fan_out'),
            'packages' => $report->targets(PairsDocument::EXTERNAL),
            'unresolved' => $report->targets(PairsDocument::UNRESOLVED),
            'dropped' => $report->dropped,
            'plain' => self::PLAIN,
            'css' => self::resource('report.css'),
            'js' => self::resource('report.js'),
            'json' => json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        $render = static function (array $view): string {
            extract($view);
            $e = static fn (string|int|float $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ob_start();
            require self::RESOURCES . '/report.html.php';
            return (string) ob_get_clean();
        };
        return $render($view);
    }

    /** "3 files", "1 group": counts with the right noun. */
    public static function count(int $count, string $singular, ?string $plural = null): string
    {
        return self::number($count) . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }

    /** A whole number as shown to readers, with thousands separated: "6,426". */
    public static function number(int $value): string
    {
        return number_format($value);
    }

    /** The instability value as shown to readers: two decimals, or "none" when undefined. */
    public static function instability(float|int|null $value): string
    {
        return $value === null ? 'none' : number_format((float) $value, 2);
    }

    /** A readable name for an unresolved node id such as `unresolved:class:App\Foo`. */
    public static function unresolvedName(string $id): string
    {
        $parts = explode(':', $id, 3);
        return count($parts) === 3 ? "{$parts[2]} ({$parts[1]})" : $id;
    }

    private static function describeGrouping(?string $clustering, string $language = 'php'): string
    {
        if ($clustering === null) {
            return 'Each part is one file.';
        }
        if ($clustering === 'rules') {
            return 'Each part is a group of files, as set by a rules file.';
        }
        [$strategy, $depth] = explode(':', $clustering);
        $levels = self::count((int) $depth, 'level');
        // Python has packages where PHP has namespaces.
        $namespace = $language === 'python' ? 'package path' : 'namespace';
        return $strategy === 'namespace'
            ? "Each part is a group of files that share the first {$levels} of their {$namespace}."
            : "Each part is a group of files that share the first {$levels} of their folder path.";
    }

    /**
     * @param list<array<string, mixed>> $units
     * @return list<array<string, mixed>> the units with the highest non-zero $metric, highest first
     */
    private static function top(array $units, string $metric): array
    {
        $units = array_values(array_filter($units, static fn (array $unit): bool => $unit[$metric] > 0));
        usort($units, static fn (array $a, array $b): int => [$b[$metric], $a['id']] <=> [$a[$metric], $b['id']]);
        return array_slice($units, 0, self::TOP);
    }

    private static function resource(string $name): string
    {
        $contents = file_get_contents(self::RESOURCES . '/' . $name);
        if ($contents === false) {
            throw new \RuntimeException("missing report resource {$name}");
        }
        return $contents;
    }
}

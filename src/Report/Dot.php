<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Report;

use Untangled\Graph\PairsDocument;

/**
 * Writes the unit graph as Graphviz DOT, byte for byte as spec/report.md describes, so it can
 * be rendered with `dot -Tsvg` or opened in any Graphviz viewer. Two styles: the default one,
 * and `classic`, the black and white look of snakefood's sfood-graph.
 */
final class Dot
{
    /** The status colour for cycles, shared with the HTML report. */
    public const CYCLE_COLOR = '#d03b3b';

    public const STYLES = ['default', 'classic'];

    public static function render(Report $report, string $style = 'default'): string
    {
        if ($style === 'classic') {
            return self::classic($report);
        }
        $clustered = $report->isClustered();
        $cycleOf = $report->cycleOf();
        $lines = [
            'digraph untangled {',
            '  graph [layout=dot, rankdir=LR, fontname="Helvetica"];',
            '  node [shape=box, style=rounded, fontname="Helvetica"];',
            '  edge [color="#898781"];',
        ];
        if ($report->analysis['units'] !== []) {
            $lines[] = '';
        }
        $highlight = 'color="' . self::CYCLE_COLOR . '", penwidth=2';
        foreach ($report->analysis['units'] as $unit) {
            $attributes = [];
            if ($clustered) {
                $attributes[] = self::label($unit);
            }
            if (isset($cycleOf[$unit['id']])) {
                $attributes[] = $highlight;
            }
            $lines[] = '  ' . self::quote($unit['id']) . self::attributes($attributes) . ';';
        }
        $edges = $report->unitEdges();
        if ($edges !== []) {
            $lines[] = '';
        }
        foreach ($edges as $edge) {
            $lines[] = '  ' . self::quote($edge['from']) . ' -> ' . self::quote($edge['to'])
                . self::attributes($edge['cycle'] ? [$highlight] : []) . ';';
        }
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    /**
     * sfood-graph's look: grey ellipses for the project's units, white ones for the external
     * packages and unresolved references they use, plain black edges, small serif labels.
     */
    private static function classic(Report $report): string
    {
        $clustered = $report->isClustered();
        $lines = [
            'digraph untangled {',
            '  graph [layout=dot, rankdir=LR];',
            '  node [shape=ellipse, style=filled, fillcolor=lightgrey, fontname="Times-Roman", fontsize=7];',
            '  edge [color=black];',
        ];
        $units = [];
        $nodes = [];
        foreach ($report->analysis['units'] as $unit) {
            $units[$unit['id']] = true;
            $nodes[] = '  ' . self::quote($unit['id']) . ($clustered ? ' [' . self::label($unit) . ']' : '') . ';';
        }
        $outside = [];
        foreach ($report->graph->nodes() as $node) {
            if ($node['kind'] === PairsDocument::EXTERNAL || $node['kind'] === PairsDocument::UNRESOLVED) {
                $outside[$node['id']] = false;
            }
        }
        $edges = [];
        foreach ($report->graph->edges() as $edge) {
            if (!isset($units[$edge['from']]) || !(isset($units[$edge['to']]) || isset($outside[$edge['to']]))) {
                continue;
            }
            if (isset($outside[$edge['to']])) {
                $outside[$edge['to']] = true;
            }
            $edges[] = '  ' . self::quote($edge['from']) . ' -> ' . self::quote($edge['to']) . ';';
        }
        foreach ($outside as $id => $used) {
            if ($used === true) {
                $nodes[] = '  ' . self::quote($id) . ' [fillcolor=white];';
            }
        }
        foreach ([$nodes, $edges] as $block) {
            if ($block !== []) {
                $lines[] = '';
                array_push($lines, ...$block);
            }
        }
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    /**
     * A DOT quoted string. Backslashes are doubled so namespace separators survive as ids and as
     * labels; a newline becomes DOT's centred line break.
     */
    public static function quote(string $text): string
    {
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $text) . '"';
    }

    /** @param array{id: string, members: list<string>} $unit a cluster unit */
    private static function label(array $unit): string
    {
        $files = count($unit['members']);
        return 'label=' . self::quote($unit['id'] . "\n" . $files . ($files === 1 ? ' file' : ' files'));
    }

    /** @param list<string> $attributes */
    private static function attributes(array $attributes): string
    {
        return $attributes === [] ? '' : ' [' . implode(', ', $attributes) . ']';
    }
}

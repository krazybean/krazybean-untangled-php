<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Report;

use Untangled\Analysis\Analyzer;
use Untangled\Analysis\Clustering;
use Untangled\Graph\PairsDocument;

/**
 * Everything a report shows (spec/report.md): the analysis document plus the unit graph it was
 * computed on, so renderers can draw edges and name the packages and references behind the counts.
 */
final class Report
{
    /**
     * @param array<string, mixed> $analysis the analysis document
     * @param PairsDocument $graph the unit graph: the filtered file graph, or its clustered form
     * @param array<string, int> $dropped per cluster, how many file edges fell inside it
     */
    private function __construct(
        public readonly string $title,
        public readonly array $analysis,
        public readonly PairsDocument $graph,
        public readonly array $dropped,
    ) {
    }

    /**
     * @param PairsDocument $files a filtered file-level pairs document
     * @throws \InvalidArgumentException when the document is already clustered
     */
    public static function build(string $title, PairsDocument $files, ?Clustering $clustering = null): self
    {
        $analysis = (new Analyzer())->analyze($files, $clustering);
        $graph = $files;
        $dropped = [];
        if ($clustering !== null) {
            ['document' => $graph, 'dropped' => $dropped] = $clustering->apply($files);
        }
        return new self($title, $analysis, $graph, $dropped);
    }

    public function isClustered(): bool
    {
        return $this->analysis['clustering'] !== null;
    }

    /** @return array<string, int> each unit in a cycle, mapped to the cycle's position (from 1) */
    public function cycleOf(): array
    {
        $cycleOf = [];
        foreach ($this->analysis['cycles'] as $index => $cycle) {
            foreach ($cycle['members'] as $member) {
                $cycleOf[$member] = $index + 1;
            }
        }
        return $cycleOf;
    }

    /**
     * Edges between two units, in document order.
     * @return list<array{from: string, to: string, count: int, cycle: bool}> `cycle` is true when
     *         both ends are in the same cycle, so the edge is part of the loop
     */
    public function unitEdges(): array
    {
        $units = array_flip(array_column($this->analysis['units'], 'id'));
        $cycleOf = $this->cycleOf();
        $edges = [];
        foreach ($this->graph->edges() as $edge) {
            if (!isset($units[$edge['from']], $units[$edge['to']])) {
                continue;
            }
            $loop = isset($cycleOf[$edge['from']]) && ($cycleOf[$edge['to']] ?? null) === $cycleOf[$edge['from']];
            $edges[] = ['from' => $edge['from'], 'to' => $edge['to'], 'count' => $edge['count'], 'cycle' => $loop];
        }
        return $edges;
    }

    /**
     * The units the map draws (spec/report.md): all of them up to $limit, else the $limit with the
     * most connections (fan-in plus fan-out, ties by id), in the order of the analysis.
     * @return list<string>
     */
    public function mapUnits(int $limit): array
    {
        $units = $this->analysis['units'];
        if (count($units) <= $limit) {
            return array_column($units, 'id');
        }
        $ranked = $units;
        usort($ranked, static fn (array $a, array $b): int => [$b['fan_in'] + $b['fan_out'], $a['id']] <=> [$a['fan_in'] + $a['fan_out'], $b['id']]);
        $keep = array_flip(array_column(array_slice($ranked, 0, $limit), 'id'));
        return array_values(array_filter(array_column($units, 'id'), static fn (string $id): bool => isset($keep[$id])));
    }

    /**
     * The external or unresolved nodes the units depend on, with the units that use each.
     * @return array<string, list<string>> node id => sorted unit ids, the most used first, ties by node id
     */
    public function targets(string $kind): array
    {
        $users = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node['kind'] === $kind) {
                $users[$node['id']] = [];
            }
        }
        foreach ($this->graph->edges() as $edge) {
            if (isset($users[$edge['to']])) {
                $users[$edge['to']][] = $edge['from'];
            }
        }
        foreach ($users as &$ids) {
            sort($ids, SORT_STRING);
        }
        unset($ids);
        uksort($users, static fn (string $a, string $b): int => [count($users[$b]), $a] <=> [count($users[$a]), $b]);
        return $users;
    }
}

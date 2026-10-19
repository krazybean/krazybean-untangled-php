<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

use Untangled\Graph\Json;
use Untangled\Graph\PairsDocument;
use Untangled\Version;

/**
 * Computes the analysis document of spec/metrics.md from a filtered file-level pairs document:
 * per-unit coupling, Ca / Ce for clusters, cycles, and the project summary.
 */
final class Analyzer
{
    /**
     * @return array<string, mixed> the analysis document
     * @throws \InvalidArgumentException when the document is already clustered
     */
    public function analyze(PairsDocument $files, ?Clustering $clustering = null): array
    {
        foreach ($files->nodes() as $node) {
            if ($node['kind'] === PairsDocument::CLUSTER) {
                throw new \InvalidArgumentException('the pairs document is already clustered; analyze the file-level document and choose a clustering instead');
            }
        }

        $graph = $files;
        $clusterOf = null;
        if ($clustering !== null) {
            ['document' => $graph, 'clusterOf' => $clusterOf] = $clustering->apply($files);
        }

        $unitKind = $clustering === null ? PairsDocument::INTERNAL : PairsDocument::CLUSTER;
        $successors = [];
        $predecessors = [];
        $external = [];
        $unresolved = [];
        $members = [];
        foreach ($graph->nodes() as $node) {
            if ($node['kind'] === $unitKind) {
                $successors[$node['id']] = [];
                $predecessors[$node['id']] = [];
                $external[$node['id']] = [];
                $unresolved[$node['id']] = [];
                $members[$node['id']] = $node['members'] ?? [];
            }
        }

        $edges = 0;
        $externalUsed = [];
        $unresolvedUsed = [];
        foreach ($graph->edges() as $edge) {
            ['from' => $from, 'to' => $to] = $edge;
            $kind = $graph->node($to)['kind'] ?? null;
            if ($kind === $unitKind) {
                $successors[$from][] = $to;
                $predecessors[$to][] = $from;
                $edges++;
            } elseif ($kind === PairsDocument::EXTERNAL) {
                $external[$from][$to] = true;
                $externalUsed[$to] = true;
            } elseif ($kind === PairsDocument::UNRESOLVED) {
                $unresolved[$from][$to] = true;
                $unresolvedUsed[$to] = true;
            }
        }

        // Ca / Ce of a cluster count the distinct files outside it, on the file graph.
        $ca = [];
        $ce = [];
        if ($clusterOf !== null) {
            foreach ($files->edges() as $edge) {
                $from = $clusterOf[$edge['from']] ?? null;
                $to = $clusterOf[$edge['to']] ?? null;
                if ($from === null || $to === null || $from === $to) {
                    continue;
                }
                $ce[$from][$edge['to']] = true;
                $ca[$to][$edge['from']] = true;
            }
        }

        $units = [];
        $fanOutTotal = 0;
        foreach ($successors as $id => $targets) {
            $id = (string) $id;
            $fanIn = count($predecessors[$id]);
            $fanOut = count($targets);
            $fanOutTotal += $fanOut;
            $unit = ['id' => $id, 'fan_in' => $fanIn, 'fan_out' => $fanOut];
            if ($clusterOf !== null) {
                $unitCa = count($ca[$id] ?? []);
                $unitCe = count($ce[$id] ?? []);
                $unit += ['ca' => $unitCa, 'ce' => $unitCe];
                [$afferent, $efferent] = [$unitCa, $unitCe];
            } else {
                [$afferent, $efferent] = [$fanIn, $fanOut];
            }
            $unit += [
                'external' => count($external[$id]),
                'unresolved' => count($unresolved[$id]),
                'instability' => $afferent + $efferent === 0 ? null : Json::number($efferent / ($afferent + $efferent)),
            ];
            if ($clusterOf !== null) {
                $unit['members'] = $members[$id];
            }
            $units[] = $unit;
        }
        usort($units, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $cycles = Cycles::find($successors);
        $inCycles = array_sum(array_column($cycles, 'size'));
        $count = count($units);

        return [
            'format' => 'untangled-analysis',
            'version' => Version::SPEC,
            'language' => $files->language(),
            'generator' => ['name' => Version::NAME, 'version' => Version::VERSION],
            'clustering' => $clustering?->name(),
            'summary' => [
                'units' => $count,
                'edges' => $edges,
                'cycles' => count($cycles),
                'largest_cycle' => $cycles === [] ? 0 : $cycles[0]['size'],
                'units_in_cycles' => $inCycles,
                'units_in_cycles_ratio' => $count === 0 ? 0 : Json::number($inCycles / $count),
                'mean_fan_out' => $count === 0 ? 0 : Json::number($fanOutTotal / $count),
                'external_packages' => count($externalUsed),
                'unresolved_references' => count($unresolvedUsed),
            ],
            'units' => $units,
            'cycles' => $cycles,
        ];
    }
}

<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

use Untangled\Graph\PairsDocument;
use Untangled\Version;

/**
 * The dependents query of spec/dependents.md: which files depend on some nodes, directly or
 * through other files, read from a file-level pairs document.
 */
final class Dependents
{
    /**
     * @param non-empty-list<string> $targets node ids or declared symbols
     * @param ?Filter $filter keeps only the dependents it keeps; the walk itself is not filtered
     * @return array<string, mixed> the dependents document
     * @throws \InvalidArgumentException when the document is clustered or a target matches nothing
     */
    public function find(PairsDocument $files, array $targets, bool $transitive, ?Filter $filter = null): array
    {
        $symbols = [];
        foreach ($files->nodes() as $node) {
            if ($node['kind'] === PairsDocument::CLUSTER) {
                throw new \InvalidArgumentException('the pairs document is already clustered; ask about the file-level document instead');
            }
            // Only files declare symbols a target can name; other kinds are ignored, as in the other libraries.
            if ($node['kind'] !== PairsDocument::INTERNAL) {
                continue;
            }
            foreach ($node['symbols'] ?? [] as $symbol) {
                $symbols[$symbol][] = $node['id'];
            }
        }

        $resolved = [];
        $start = [];
        foreach ($targets as $target) {
            $nodes = $files->hasNode($target) ? [$target] : ($symbols[$target] ?? []);
            if ($nodes === []) {
                throw new \InvalidArgumentException("no node or symbol \"{$target}\" in the pairs document");
            }
            sort($nodes, SORT_STRING);
            $resolved[] = ['target' => $target, 'nodes' => $nodes];
            foreach ($nodes as $id) {
                $start[$id] = true;
            }
        }

        $dependentsOf = [];
        foreach ($files->edges() as $edge) {
            $dependentsOf[$edge['to']][] = $edge['from'];
        }

        // Breadth-first from the targets, so the first time a file is reached is its shortest distance.
        $depth = [];
        $frontier = array_keys($start);
        for ($level = 1; $frontier !== [] && ($transitive || $level === 1); $level++) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($dependentsOf[$id] ?? [] as $from) {
                    if (!isset($start[$from]) && !isset($depth[$from])) {
                        $depth[$from] = $level;
                        $next[] = $from;
                    }
                }
            }
            $frontier = $next;
        }

        $dependents = [];
        foreach ($depth as $id => $level) {
            if ($filter === null || $filter->keeps((string) $id)) {
                $dependents[] = ['id' => (string) $id, 'depth' => $level];
            }
        }
        // By byte value: strcmp, since `<=>` compares numeric-looking ids ("9", "10") as numbers.
        usort($dependents, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth'] ?: strcmp($a['id'], $b['id']));

        return [
            'format' => 'untangled-dependents',
            'version' => Version::SPEC,
            'language' => $files->language(),
            'generator' => ['name' => Version::NAME, 'version' => Version::VERSION],
            'transitive' => $transitive,
            'targets' => $resolved,
            'dependents' => $dependents,
        ];
    }

    /**
     * The text form for people: a count, then the dependents grouped by folder.
     *
     * @param array<string, mixed> $document a dependents document
     */
    public static function renderText(array $document): string
    {
        $targets = implode(', ', array_column($document['targets'], 'target'));
        $count = count($document['dependents']);
        if ($count === 0) {
            return "No dependents of {$targets}\n";
        }

        $folders = [];
        foreach ($document['dependents'] as $dependent) {
            $slash = strrpos($dependent['id'], '/');
            $folder = $slash === false ? './' : substr($dependent['id'], 0, $slash + 1);
            $name = $slash === false ? $dependent['id'] : substr($dependent['id'], $slash + 1);
            $folders[$folder][$name] = $dependent['depth'];
        }
        ksort($folders, SORT_STRING);

        $text = $count . ($count === 1 ? ' dependent' : ' dependents') . " of {$targets}\n";
        foreach ($folders as $folder => $names) {
            ksort($names, SORT_STRING);
            $text .= "\n{$folder}\n";
            foreach ($names as $name => $depth) {
                $text .= "  {$name}" . ($document['transitive'] ? "  (depth {$depth})" : '') . "\n";
            }
        }
        return $text;
    }
}

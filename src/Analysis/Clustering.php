<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

use Untangled\Graph\PairsDocument;

/**
 * A clustering strategy from spec/clustering.md (`namespace:N`, `folder:N` or `rules`) and the
 * step that applies it: one node per cluster, edges merged per (cluster, target), and edges
 * inside a cluster dropped.
 */
final class Clustering
{
    /** @param list<array{prefix: string, cluster: string}> $rules */
    private function __construct(
        public readonly string $strategy,
        private readonly int $depth = 0,
        private readonly array $rules = [],
    ) {
    }

    public static function byNamespace(int $depth): self
    {
        self::checkDepth($depth);
        return new self('namespace', $depth);
    }

    public static function byFolder(int $depth): self
    {
        self::checkDepth($depth);
        return new self('folder', $depth);
    }

    /** @param list<array{prefix: string, cluster: string}> $rules ordered; the first matching prefix wins */
    public static function byRules(array $rules): self
    {
        return new self('rules', 0, $rules);
    }

    /**
     * Parses a rules file: one `prefix cluster` pair per line, `#` starts a comment.
     * @throws \InvalidArgumentException
     */
    public static function parseRules(string $text): self
    {
        $rules = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $fields = preg_split('/\s+/', $line) ?: [];
            if (count($fields) !== 2) {
                throw new \InvalidArgumentException('rules line ' . ($number + 1) . ' must be "<prefix> <cluster>"');
            }
            $rules[] = ['prefix' => $fields[0], 'cluster' => $fields[1]];
        }
        return self::byRules($rules);
    }

    /**
     * Parses a strategy as written on the command line: `namespace:N` or `folder:N`.
     * (`rules` needs a rules file, so it is built with parseRules().)
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $strategy): self
    {
        if (preg_match('/\A(namespace|folder):([1-9][0-9]*)\z/', $strategy, $m) !== 1) {
            throw new \InvalidArgumentException("unknown clustering \"{$strategy}\" (use namespace:N, folder:N or rules:<file>)");
        }
        return $m[1] === 'namespace' ? self::byNamespace((int) $m[2]) : self::byFolder((int) $m[2]);
    }

    /** The name written in the analysis document's `clustering` field. */
    public function name(): string
    {
        return $this->strategy === 'rules' ? 'rules' : "{$this->strategy}:{$this->depth}";
    }

    /** @param array<string, mixed> $node an internal node of a pairs document */
    public function clusterOf(array $node): string
    {
        $id = $node['id'];
        if ($this->strategy === 'rules') {
            foreach ($this->rules as $rule) {
                if (str_starts_with($id, $rule['prefix'])) {
                    return $rule['cluster'];
                }
            }
            return $id;
        }
        if ($this->strategy === 'namespace' && is_string($node['module'] ?? null) && $node['module'] !== '') {
            // Segments are separated by `\` (PHP namespaces) or `.` (Python modules); the
            // separators are kept, so the cluster id reads like the module it shortens.
            $parts = preg_split('/([\\\\.])/', $node['module'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$node['module']];
            return implode('', array_slice($parts, 0, 2 * $this->depth - 1));
        }
        $directories = explode('/', $id);
        array_pop($directories);
        return $directories === [] ? '.' : implode('/', array_slice($directories, 0, $this->depth));
    }

    /**
     * Applies the strategy. Non-internal nodes pass through unchanged.
     * @return array{document: PairsDocument, clusterOf: array<string, string>, dropped: array<string, int>}
     *         the clustered document, each internal id's cluster, and per cluster how many
     *         file edges fell inside it and were dropped
     */
    public function apply(PairsDocument $files): array
    {
        $clusterOf = [];
        $members = [];
        $others = [];
        foreach ($files->nodes() as $node) {
            if ($node['kind'] === PairsDocument::INTERNAL) {
                $cluster = $this->clusterOf($node);
                $clusterOf[$node['id']] = $cluster;
                $members[$cluster][] = $node['id'];
            } else {
                $others[] = $node;
            }
        }

        $document = new PairsDocument($files->language());
        foreach ($members as $cluster => $ids) {
            sort($ids, SORT_STRING);
            $document->addNode((string) $cluster, PairsDocument::CLUSTER, ['members' => $ids]);
        }
        foreach ($others as $node) {
            $document->addNode($node['id'], $node['kind'], array_diff_key($node, ['id' => true, 'kind' => true]));
        }

        $dropped = [];
        foreach ($files->edges() as $edge) {
            $from = $clusterOf[$edge['from']] ?? $edge['from'];
            $to = $clusterOf[$edge['to']] ?? $edge['to'];
            if ($from === $to) {
                $dropped[$from] = ($dropped[$from] ?? 0) + 1;
                continue;
            }
            $document->mergeEdge($from, $to, $edge['kinds'], $edge['count']);
        }
        ksort($dropped, SORT_STRING);
        return ['document' => $document, 'clusterOf' => $clusterOf, 'dropped' => $dropped];
    }

    private static function checkDepth(int $depth): void
    {
        if ($depth < 1) {
            throw new \InvalidArgumentException('clustering depth must be at least 1');
        }
    }
}

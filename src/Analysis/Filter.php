<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

use Untangled\Graph\PairsDocument;

/**
 * The filtering step of spec/clustering.md: drops builtin nodes (unless kept), then applies
 * ordered include / exclude globs to internal ids, where the last matching pattern wins.
 */
final class Filter
{
    /** @var list<array{include: bool, glob: Glob}> */
    private array $patterns = [];

    public function __construct(private readonly bool $keepBuiltins = false)
    {
    }

    public function include(string $pattern): self
    {
        $this->patterns[] = ['include' => true, 'glob' => new Glob($pattern)];
        return $this;
    }

    public function exclude(string $pattern): self
    {
        $this->patterns[] = ['include' => false, 'glob' => new Glob($pattern)];
        return $this;
    }

    public function keeps(string $internalId): bool
    {
        $keep = true;
        foreach ($this->patterns as $pattern) {
            if ($pattern['glob']->matches($internalId)) {
                $keep = $pattern['include'];
            }
        }
        return $keep;
    }

    /**
     * Returns the filtered document. Removing a node removes its edges, and a non-internal node
     * that no remaining edge points to is removed with them.
     */
    public function apply(PairsDocument $document): PairsDocument
    {
        $kept = [];
        foreach ($document->nodes() as $node) {
            if ($node['kind'] === PairsDocument::BUILTIN && !$this->keepBuiltins) {
                continue;
            }
            if ($node['kind'] === PairsDocument::INTERNAL && !$this->keeps($node['id'])) {
                continue;
            }
            $kept[$node['id']] = $node;
        }

        $edges = [];
        $targets = [];
        foreach ($document->edges() as $edge) {
            if (isset($kept[$edge['from']], $kept[$edge['to']])) {
                $edges[] = $edge;
                $targets[$edge['to']] = true;
            }
        }

        $filtered = new PairsDocument($document->language());
        foreach ($kept as $id => $node) {
            // Cluster nodes pass through so that the analyzer can refuse an already clustered document.
            if (in_array($node['kind'], [PairsDocument::INTERNAL, PairsDocument::CLUSTER], true) || isset($targets[$id])) {
                $filtered->addNode($id, $node['kind'], array_diff_key($node, ['id' => true, 'kind' => true]));
            }
        }
        foreach ($edges as $edge) {
            $filtered->mergeEdge($edge['from'], $edge['to'], $edge['kinds'], $edge['count']);
        }
        return $filtered;
    }
}

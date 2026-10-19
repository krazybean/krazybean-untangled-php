<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Graph;

use Untangled\Text;
use Untangled\Version;

/**
 * The pairs document from spec/pairs-format.md: nodes plus merged (from, to) edges.
 */
final class PairsDocument
{
    public const INTERNAL = 'internal';
    public const EXTERNAL = 'external';
    public const BUILTIN = 'builtin';
    public const UNRESOLVED = 'unresolved';
    public const CLUSTER = 'cluster';

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, array{from: string, to: string, kinds: array<string, true>, count: int}> */
    private array $edges = [];

    public function __construct(private readonly string $language)
    {
    }

    /**
     * Ids, and the strings in $extra, are stored as UTF-8 (see Text::utf8), so a name read from
     * Latin-1 source can be passed in as it was read.
     * @param array<string, mixed> $extra
     */
    public function addNode(string $id, string $kind, array $extra = []): void
    {
        $id = Text::utf8($id);
        $this->nodes[$id] ??= ['id' => $id, 'kind' => $kind] + self::utf8Values($extra);
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[Text::utf8($id)]);
    }

    /** @return array<string, mixed>|null */
    public function node(string $id): ?array
    {
        return $this->nodes[Text::utf8($id)] ?? null;
    }

    public function language(): string
    {
        return $this->language;
    }

    /** Records one reference; repeated references to the same target merge. Self-edges are dropped. */
    public function addEdge(string $from, string $to, string $kind): void
    {
        $this->mergeEdge($from, $to, [$kind], 1);
    }

    /**
     * Merges an already-counted edge, as when reading a document or combining edges into clusters.
     * @param list<string> $kinds
     */
    public function mergeEdge(string $from, string $to, array $kinds, int $count): void
    {
        $from = Text::utf8($from);
        $to = Text::utf8($to);
        if ($from === $to) {
            return;
        }
        $key = $from . "\0" . $to;
        $this->edges[$key] ??= ['from' => $from, 'to' => $to, 'kinds' => [], 'count' => 0];
        foreach ($kinds as $kind) {
            $this->edges[$key]['kinds'][$kind] = true;
        }
        $this->edges[$key]['count'] += $count;
    }

    /**
     * Reads a pairs document, such as one written by `untangled scan` or by another library.
     * @param array<string, mixed> $data
     * @param list<string> $warnings receives a warning for an unknown minor version
     * @throws DocumentException
     */
    public static function fromArray(array $data, array &$warnings = []): self
    {
        if (($data['format'] ?? null) !== 'untangled-pairs') {
            throw new DocumentException('not an Untangled pairs document (expected "format": "untangled-pairs")');
        }
        $version = is_string($data['version'] ?? null) ? $data['version'] : '';
        [$major, $minor] = array_pad(explode('.', $version, 2), 2, '');
        [$knownMajor, $knownMinor] = explode('.', Version::SPEC, 2);
        if ($major !== $knownMajor) {
            throw new DocumentException("unsupported pairs document version \"{$version}\" (this library reads " . Version::SPEC . ')');
        }
        if ($minor !== $knownMinor) {
            $warnings[] = "pairs document version {$version} is newer or older than " . Version::SPEC . '; reading it anyway';
        }
        if (!is_string($data['language'] ?? null) || !is_array($data['nodes'] ?? null) || !is_array($data['edges'] ?? null)) {
            throw new DocumentException('pairs document needs "language", "nodes" and "edges"');
        }

        $document = new self($data['language']);
        foreach ($data['nodes'] as $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null) || !is_string($node['kind'] ?? null)) {
                throw new DocumentException('every node needs a string "id" and "kind"');
            }
            $document->addNode($node['id'], $node['kind'], array_diff_key($node, ['id' => true, 'kind' => true]));
        }
        foreach ($data['edges'] as $edge) {
            if (!is_array($edge) || !is_string($edge['from'] ?? null) || !is_string($edge['to'] ?? null)) {
                throw new DocumentException('every edge needs a string "from" and "to"');
            }
            if (!$document->hasNode($edge['from']) || !$document->hasNode($edge['to'])) {
                throw new DocumentException("edge {$edge['from']} -> {$edge['to']} names a node that is not in the document");
            }
            $kinds = array_values(array_filter((array) ($edge['kinds'] ?? []), 'is_string'));
            $document->mergeEdge($edge['from'], $edge['to'], $kinds, max(1, (int) ($edge['count'] ?? 1)));
        }
        return $document;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function utf8Values(array $values): array
    {
        foreach ($values as &$value) {
            if (is_string($value)) {
                $value = Text::utf8($value);
            } elseif (is_array($value)) {
                $value = self::utf8Values($value);
            }
        }
        return $values;
    }

    /** @return list<array{from: string, to: string, kinds: list<string>, count: int}> */
    public function edges(): array
    {
        $edges = [];
        foreach ($this->edges as $edge) {
            $kinds = array_keys($edge['kinds']);
            sort($kinds, SORT_STRING);
            $edges[] = ['from' => $edge['from'], 'to' => $edge['to'], 'kinds' => $kinds, 'count' => $edge['count']];
        }
        usort($edges, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
        return $edges;
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        $nodes = $this->nodes;
        ksort($nodes, SORT_STRING);
        return array_values($nodes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => 'untangled-pairs',
            'version' => Version::SPEC,
            'language' => $this->language,
            'generator' => ['name' => Version::NAME, 'version' => Version::VERSION],
            'nodes' => $this->nodes(),
            'edges' => $this->edges(),
        ];
    }

    public function toJson(): string
    {
        return Json::encode($this->toArray());
    }

    /**
     * snakefood's line format: ((root, from), (root, to)), and ((root, file), (None, None))
     * for files without internal dependencies. Only internal nodes (or clusters) are written.
     */
    public function toSnakefood(string $root): string
    {
        $quote = static fn (string $s): string => "'" . addcslashes($s, "\\'") . "'";
        $isUnit = fn (string $id): bool => in_array($this->nodes[$id]['kind'] ?? null, [self::INTERNAL, self::CLUSTER], true);
        $lines = [];
        $hasDeps = [];
        foreach ($this->edges() as $edge) {
            if (!$isUnit($edge['to'])) {
                continue;
            }
            $hasDeps[$edge['from']] = true;
            $lines[] = sprintf('((%s, %s), (%s, %s))', $quote($root), $quote($edge['from']), $quote($root), $quote($edge['to']));
        }
        foreach ($this->nodes() as $node) {
            if ($isUnit($node['id']) && !isset($hasDeps[$node['id']])) {
                $lines[] = sprintf('((%s, %s), (None, None))', $quote($root), $quote($node['id']));
            }
        }
        sort($lines, SORT_STRING);
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}

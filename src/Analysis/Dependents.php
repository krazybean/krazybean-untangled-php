<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

use Untangled\Graph\PairsDocument;
use Untangled\Path;
use Untangled\Version;

/**
 * The dependents query of spec/dependents.md: which files depend on some nodes, directly or
 * through other files, read from a file-level pairs document.
 */
final class Dependents
{
    /**
     * @param non-empty-list<string> $targets node ids, declared symbols, or paths and symbols spelled another way
     * @param ?Filter $filter keeps only the dependents it keeps once the walk is done
     * @param list<string> $through globs; when there are any, the walk only goes into files matching one
     * @param ?int $maxDepth with $transitive, the deepest dependents to find; null for no limit
     * @param ?string $root the absolute project folder, to accept absolute paths inside it as targets
     * @return array<string, mixed> the dependents document
     * @throws \InvalidArgumentException when the document is clustered, a target matches nothing or is
     *                                   an absolute path outside $root, or $maxDepth is below 1 or given without $transitive
     */
    public function find(
        PairsDocument $files,
        array $targets,
        bool $transitive,
        ?Filter $filter = null,
        array $through = [],
        ?int $maxDepth = null,
        ?string $root = null,
    ): array {
        if ($maxDepth !== null && $maxDepth < 1) {
            throw new \InvalidArgumentException('the maximum depth must be a positive integer');
        }
        if ($maxDepth !== null && !$transitive) {
            throw new \InvalidArgumentException('the maximum depth only applies to a transitive search');
        }
        $eligible = array_map(static fn (string $glob): Glob => new Glob($glob), $through);

        $symbols = [];
        foreach ($files->nodes() as $node) {
            if ($node['kind'] === PairsDocument::CLUSTER) {
                throw new DependentsException('the pairs document is already clustered; ask about the file-level document instead', 'document-clustered');
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
                $nodes = self::respelled($files, $symbols, $target, $root);
            }
            if ($nodes === []) {
                throw new DependentsException("no node or symbol \"{$target}\" in the pairs document", 'target-not-found');
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
        // A file the walk may not go into is never reached, so nothing is found by way of it.
        $deepest = $transitive ? ($maxDepth ?? PHP_INT_MAX) : 1;
        $depth = [];
        $frontier = array_keys($start);
        for ($level = 1; $frontier !== [] && $level <= $deepest; $level++) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($dependentsOf[$id] ?? [] as $from) {
                    if (!isset($start[$from]) && !isset($depth[$from]) && self::mayEnter($eligible, (string) $from)) {
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

    /** @param list<Glob> $eligible */
    private static function mayEnter(array $eligible, string $id): bool
    {
        if ($eligible === []) {
            return true;
        }
        foreach ($eligible as $glob) {
            if ($glob->matches($id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The nodes a target names when it is not an id or a symbol as written: the path or the PHP
     * class name spelled another way. Empty when it names none.
     *
     * @param array<string, list<string>> $symbols
     * @return list<string>
     * @throws \InvalidArgumentException for an absolute path outside the project folder
     */
    private static function respelled(PairsDocument $files, array $symbols, string $target, ?string $root): array
    {
        $unslashed = ltrim($target, '\\');
        if ($unslashed !== $target && isset($symbols[$unslashed])) {
            return $symbols[$unslashed];
        }
        $path = $target;
        if ($root !== null && Path::isAbsolute($target)) {
            $path = self::insideProject($target, $root)
                ?? throw new DependentsException("\"{$target}\" is outside the project folder {$root}", 'target-outside-project');
        }
        $path = Path::normalize($path);
        return $path !== '' && $files->hasNode($path) ? [$path] : [];
    }

    /** The project-relative form of an absolute path, or null when it is not inside $root. */
    private static function insideProject(string $path, string $root): ?string
    {
        $roots = [Path::normalize($root)];
        $realRoot = realpath($root);
        if ($realRoot !== false) {
            $roots[] = Path::normalize($realRoot);
        }
        $paths = [Path::normalize($path)];
        $realPath = realpath($path);
        if ($realPath !== false) {
            $paths[] = Path::normalize($realPath);
        }
        foreach ($paths as $candidate) {
            foreach ($roots as $base) {
                $relative = Path::relative($base, $candidate);
                if ($relative !== null) {
                    return $relative;
                }
            }
        }
        return null;
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

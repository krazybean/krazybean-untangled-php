<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

/**
 * Cycles as defined in spec/metrics.md: strongly connected components with two or more units,
 * each with one concrete example loop.
 */
final class Cycles
{
    /**
     * @param array<string, list<string>> $successors every unit as a key, with the units it depends on
     * @return list<array{members: list<string>, size: int, example: list<string>}>
     */
    public static function find(array $successors): array
    {
        $successors = self::sorted($successors);
        $cycles = [];
        foreach (self::components($successors) as $component) {
            if (count($component) < 2) {
                continue;
            }
            sort($component, SORT_STRING);
            $cycles[] = [
                'members' => $component,
                'size' => count($component),
                'example' => self::example($component[0], array_fill_keys($component, true), $successors),
            ];
        }
        usort($cycles, static fn (array $a, array $b): int => $b['size'] <=> $a['size'] ?: strcmp($a['members'][0], $b['members'][0]));
        return $cycles;
    }

    /**
     * Tarjan's algorithm, written iteratively so deep dependency chains cannot overflow the stack.
     * @param array<string, list<string>> $successors
     * @return list<list<string>>
     */
    private static function components(array $successors): array
    {
        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $components = [];
        $next = 0;

        foreach (array_keys($successors) as $root) {
            $root = (string) $root;
            if (isset($index[$root])) {
                continue;
            }
            // Each frame is [unit, position of the next successor to visit].
            $frames = [[$root, 0]];
            $index[$root] = $low[$root] = $next++;
            $stack[] = $root;
            $onStack[$root] = true;

            while ($frames !== []) {
                $top = count($frames) - 1;
                [$unit, $position] = $frames[$top];
                $targets = $successors[$unit];
                if ($position < count($targets)) {
                    $frames[$top][1]++;
                    $target = $targets[$position];
                    if (!isset($index[$target])) {
                        $index[$target] = $low[$target] = $next++;
                        $stack[] = $target;
                        $onStack[$target] = true;
                        $frames[] = [$target, 0];
                    } elseif (isset($onStack[$target])) {
                        $low[$unit] = min($low[$unit], $index[$target]);
                    }
                    continue;
                }

                array_pop($frames);
                if ($frames !== []) {
                    $parent = $frames[count($frames) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$unit]);
                }
                if ($low[$unit] === $index[$unit]) {
                    $component = [];
                    do {
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $component[] = $member;
                    } while ($member !== $unit);
                    $components[] = $component;
                }
            }
        }
        return $components;
    }

    /**
     * The shortest loop from $start back to itself, by breadth-first search visiting neighbours in
     * byte order. Any such loop stays inside the cycle, so the search does too.
     * @param array<string, true> $members
     * @param array<string, list<string>> $successors
     * @return list<string>
     */
    private static function example(string $start, array $members, array $successors): array
    {
        $parent = [];
        $queue = [$start];
        for ($head = 0; $head < count($queue); $head++) {
            $unit = $queue[$head];
            foreach ($successors[$unit] as $target) {
                if (!isset($members[$target])) {
                    continue;
                }
                if ($target === $start) {
                    $path = [$start];
                    for ($step = $unit; $step !== $start; $step = $parent[$step]) {
                        $path[] = $step;
                    }
                    $path[] = $start;
                    return array_reverse($path);
                }
                if (!isset($parent[$target])) {
                    $parent[$target] = $unit;
                    $queue[] = $target;
                }
            }
        }
        throw new \LogicException("no loop through {$start}");
    }

    /**
     * @param array<string, list<string>> $successors
     * @return array<string, list<string>>
     */
    private static function sorted(array $successors): array
    {
        ksort($successors, SORT_STRING);
        foreach ($successors as &$targets) {
            $targets = array_map('strval', array_values(array_unique($targets)));
            sort($targets, SORT_STRING);
        }
        return $successors;
    }
}

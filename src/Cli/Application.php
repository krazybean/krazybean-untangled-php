<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Cli;

use Untangled\Analysis\Analyzer;
use Untangled\Analysis\Clustering;
use Untangled\Analysis\Dependents;
use Untangled\Analysis\Filter;
use Untangled\Composer\ProjectException;
use Untangled\Graph\DocumentException;
use Untangled\Graph\Json;
use Untangled\Graph\PairsDocument;
use Untangled\Report\Dot;
use Untangled\Report\HtmlReport;
use Untangled\Report\Report;
use Untangled\Scanner\ClassAliases;
use Untangled\Scanner\Scanner;
use Untangled\Version;

/**
 * `untangled <command> [options]`. The command and option names are shared by every
 * Untangled library; v0.1 implements `scan`, `analyze` and `report`.
 */
final class Application
{
    private const USAGE = <<<'TXT'
        Usage: untangled scan [<path>] [--dev] [--source=<path>]... [--aliases=<preset|file>]...
                              [--format=json|snakefood] [--output=<file>]
               untangled analyze [<path>|<pairs.json>] [scan options] [--cluster=<strategy>] [--strict]
                                 [--include=<glob>]... [--exclude=<glob>]... [--output=<file>]
               untangled report [<path>|<pairs.json>] [--format=html|dot] [--style=classic]
                                 [--title=<text>] [analyze options]
               untangled dependents [<path>|<pairs.json>] <file-or-symbol>... [--transitive]
                                    [--format=text|json] [analyze options]

        Commands:
          scan       Write the dependency pairs of a Composer project (default path: .)
          analyze    Write cycles and coupling metrics for a project or a pairs document
          report     Write a readable HTML report (or a Graphviz DOT graph) of the analysis
          dependents List the files that depend on files or declared symbols

        Options:
          --dev              Also scan autoload-dev (tests)
          --source=PATH      Also scan the PHP files under PATH, relative to the project (repeatable),
                             for code that composer.json does not autoload
          --aliases=NAME     Resolve global class aliases registered at runtime (class_alias), from a
                             preset (laravel) or a JSON file {"aliases": {"DB": "Illuminate\\...\\DB"}}
                             (repeatable, a later one wins)
          --format=FORMAT    scan: json (default, the Untangled pairs document) or snakefood
                             report: html (default) or dot
                             dependents: text (default) or json
          --transitive       dependents: also the files that depend on a dependent, with their depth
          --style=STYLE      report --format=dot only: default, or classic for snakefood's
                             black and white sfood-graph look
          --title=TEXT       report only: the report's title (default: the Composer package name)
          --cluster=STRATEGY analyze, report: namespace:N, folder:N or rules:<file>
          --strict           analyze, report: with rules:<file>, exit 1 instead of warning when a
                             file matches no rule
          --include=GLOB     analyze, report: keep files matching GLOB (repeatable, last match wins)
          --exclude=GLOB     analyze, report: drop files matching GLOB (repeatable, last match wins)
          --output=FILE      Write to FILE instead of standard output
          -h, --help         Show this help
          -V, --version      Show the version

        TXT;

    /** @param resource $stdout @param resource $stderr */
    public function __construct(
        private $stdout = STDOUT,
        private $stderr = STDERR,
    ) {
    }

    /** @param list<string> $argv arguments without the program name */
    public function run(array $argv): int
    {
        $positional = [];
        $options = [];
        $patterns = [];
        $sources = [];
        $aliases = [];
        foreach ($argv as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                fwrite($this->stdout, self::USAGE);
                return 0;
            }
            if ($arg === '-V' || $arg === '--version') {
                fwrite($this->stdout, Version::NAME . ' ' . Version::VERSION . "\n");
                return 0;
            }
            if (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                if ($name === 'include' || $name === 'exclude') {
                    // Order matters for these, so they are kept as a list rather than by name.
                    $patterns[] = [$name, $value];
                } elseif ($name === 'source' && is_string($value)) {
                    $sources[] = $value;
                } elseif ($name === 'aliases' && is_string($value)) {
                    $aliases[] = $value;
                }
                $options[$name] = $value;
                continue;
            }
            $positional[] = $arg;
        }

        $command = array_shift($positional);
        if (!in_array($command, ['scan', 'analyze', 'report', 'dependents'], true)) {
            fwrite($this->stderr, $command === null ? self::USAGE : "Unknown command: {$command}\n\n" . self::USAGE);
            return 2;
        }
        $allowed = match ($command) {
            'scan' => ['dev', 'source', 'aliases', 'format', 'output'],
            'analyze' => ['dev', 'source', 'aliases', 'cluster', 'strict', 'include', 'exclude', 'output'],
            'report' => ['dev', 'source', 'aliases', 'cluster', 'strict', 'include', 'exclude', 'output', 'format', 'style', 'title'],
            'dependents' => ['dev', 'source', 'aliases', 'transitive', 'include', 'exclude', 'output', 'format'],
        };
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            fwrite($this->stderr, 'Unknown option for ' . $command . ': --' . reset($unknown) . "\n");
            return 2;
        }
        foreach ($options as $name => $value) {
            if (($name === 'strict' || $name === 'transitive') && $value !== true) {
                fwrite($this->stderr, "Option --{$name} takes no value\n");
                return 2;
            }
            if ($name !== 'dev' && $name !== 'strict' && $name !== 'transitive' && !is_string($value)) {
                fwrite($this->stderr, "Option --{$name} needs a value, as in --{$name}=...\n");
                return 2;
            }
        }
        try {
            $scanner = new Scanner(dev: isset($options['dev']), sources: $sources, aliases: ClassAliases::load($aliases));
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'error: ' . $e->getMessage() . "\n");
            return 1;
        }
        if ($command === 'analyze' || $command === 'report') {
            return $this->analyze($command, $positional[0] ?? '.', $options, $patterns, $scanner);
        }
        if ($command === 'dependents') {
            return $this->dependents($positional, $options, $patterns, $scanner);
        }
        $format = $options['format'] ?? 'json';
        if (!in_array($format, ['json', 'snakefood'], true)) {
            fwrite($this->stderr, "Unknown format: {$format}\n");
            return 2;
        }

        $path = $positional[0] ?? '.';
        try {
            $result = $scanner->scan($path);
        } catch (ProjectException $e) {
            fwrite($this->stderr, 'error: ' . $e->getMessage() . "\n");
            return 1;
        }
        foreach ($result->warnings as $warning) {
            fwrite($this->stderr, "warning: {$warning}\n");
        }

        $output = $format === 'json' ? $result->pairs->toJson() : $result->pairs->toSnakefood($path);
        return $this->write($output, $options['output'] ?? null);
    }

    /**
     * @param array<string, string|true> $options
     * @param list<array{string, string}> $patterns include / exclude globs in command-line order
     */
    private function analyze(string $command, string $path, array $options, array $patterns, Scanner $scanner): int
    {
        $format = $options['format'] ?? 'html';
        if ($command === 'report' && !in_array($format, ['html', 'dot'], true)) {
            fwrite($this->stderr, "Unknown format: {$format}\n");
            return 2;
        }
        $style = $options['style'] ?? 'default';
        if (!in_array($style, Dot::STYLES, true)) {
            fwrite($this->stderr, "Unknown style: {$style}\n");
            return 2;
        }
        if (isset($options['style']) && $format !== 'dot') {
            fwrite($this->stderr, "--style only applies to --format=dot\n");
            return 2;
        }
        $strategy = $options['cluster'] ?? null;
        if (isset($options['strict']) && !(is_string($strategy) && str_starts_with($strategy, 'rules:'))) {
            fwrite($this->stderr, "--strict only applies to --cluster=rules:<file>\n");
            return 2;
        }
        try {
            $clustering = null;
            if (is_string($strategy) && str_starts_with($strategy, 'rules:')) {
                $rulesFile = substr($strategy, strlen('rules:'));
                $rules = is_file($rulesFile) ? file_get_contents($rulesFile) : false;
                if ($rules === false) {
                    fwrite($this->stderr, "error: cannot read rules file {$rulesFile}\n");
                    return 1;
                }
                $clustering = Clustering::parseRules($rules);
            } elseif (is_string($strategy)) {
                $clustering = Clustering::fromString($strategy);
            }
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'error: ' . $e->getMessage() . "\n");
            return 2;
        }

        $pairs = $this->load($path, $scanner);
        if ($pairs === null) {
            return 1;
        }

        $selected = self::filter($patterns)->apply($pairs);
        $unmatched = $clustering?->unmatched($selected) ?? [];
        if ($unmatched !== []) {
            if (isset($options['strict'])) {
                fwrite($this->stderr, 'error: ' . Clustering::describeUnmatched($unmatched) . "\n");
                return 1;
            }
            fwrite($this->stderr, 'warning: ' . Clustering::describeUnmatched($unmatched) . "\n");
        }
        try {
            if ($command === 'analyze') {
                return $this->write(Json::encode((new Analyzer())->analyze($selected, $clustering)), $options['output'] ?? null);
            }
            $title = is_string($options['title'] ?? null) ? $options['title'] : self::title($path);
            $report = Report::build($title, $selected, $clustering);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'error: ' . $e->getMessage() . "\n");
            return 1;
        }
        return $this->write($format === 'dot' ? Dot::render($report, $style) : HtmlReport::render($report), $options['output'] ?? null);
    }

    /**
     * Lists the files that depend on the targets, which are the arguments after the source.
     * The source is the first argument when it is a folder or a .json file, else the current folder.
     *
     * @param list<string> $positional
     * @param array<string, string|true> $options
     * @param list<array{string, string}> $patterns include / exclude globs in command-line order
     */
    private function dependents(array $positional, array $options, array $patterns, Scanner $scanner): int
    {
        $format = $options['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            fwrite($this->stderr, "Unknown format: {$format}\n");
            return 2;
        }
        $first = $positional[0] ?? null;
        $hasSource = $first !== null && (is_dir($first) || (is_file($first) && str_ends_with(strtolower($first), '.json')));
        $targets = $hasSource ? array_slice($positional, 1) : $positional;
        if ($targets === []) {
            fwrite($this->stderr, "dependents needs at least one file or symbol to look up\n");
            return 2;
        }

        $pairs = $this->load($hasSource ? $first : '.', $scanner);
        if ($pairs === null) {
            return 1;
        }
        try {
            $document = (new Dependents())->find($pairs, $targets, isset($options['transitive']), self::filter($patterns));
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, 'error: ' . $e->getMessage() . "\n");
            return 1;
        }
        return $this->write($format === 'json' ? Json::encode($document) : Dependents::renderText($document), $options['output'] ?? null);
    }

    /** @param list<array{string, string}> $patterns include / exclude globs in command-line order */
    private static function filter(array $patterns): Filter
    {
        $filter = new Filter();
        foreach ($patterns as [$kind, $glob]) {
            $kind === 'include' ? $filter->include($glob) : $filter->exclude($glob);
        }
        return $filter;
    }

    /**
     * Reads a pairs document, or scans a project, and writes the warnings to standard error.
     * Null, after writing the error, when it cannot.
     */
    private function load(string $path, Scanner $scanner): ?PairsDocument
    {
        $warnings = [];
        try {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents === false) {
                    fwrite($this->stderr, "error: cannot read {$path}\n");
                    return null;
                }
                $pairs = PairsDocument::fromArray(Json::decode($contents, $path), $warnings);
            } else {
                $result = $scanner->scan($path);
                $pairs = $result->pairs;
                $warnings = $result->warnings;
            }
        } catch (ProjectException | DocumentException $e) {
            fwrite($this->stderr, 'error: ' . $e->getMessage() . "\n");
            return null;
        }
        foreach ($warnings as $warning) {
            fwrite($this->stderr, "warning: {$warning}\n");
        }
        return $pairs;
    }

    /** The default report title: the Composer package name, else the folder or file name. */
    private static function title(string $path): string
    {
        if (is_file($path)) {
            return basename($path);
        }
        $composer = @file_get_contents(rtrim($path, '/') . '/composer.json');
        $name = $composer === false ? null : (json_decode($composer, true)['name'] ?? null);
        if (is_string($name) && $name !== '') {
            return $name;
        }
        $real = realpath($path);
        return basename($real === false ? $path : $real);
    }

    private function write(string $output, string|true|null $file): int
    {
        if (is_string($file)) {
            if (@file_put_contents($file, $output) === false) {
                fwrite($this->stderr, "error: cannot write {$file}\n");
                return 1;
            }
        } else {
            fwrite($this->stdout, $output);
        }
        return 0;
    }
}

# Changelog

All notable changes to the PHP library. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/): the pairs document, the analysis
document and the command-line options are the public interface.

## Unreleased

## 1.0.1

- `scan` marks a file that does not parse, or cannot be read, with
  `"error": "parse"` or `"error": "read"` in the pairs document, and the
  HTML report counts and lists those files under "Files we could not read".
  Before, such a file looked like one with no dependencies.
- `scan` warns when it finds no files to scan, instead of writing an empty
  document silently.
- `scan` warns when `composer.lock` or `vendor/composer/installed.json` is
  not valid JSON. Before, every third-party class silently showed as
  unresolved.
- Package metadata: registry keywords now name code dependencies directly
  (code dependencies, circular dependencies, import graph, module
  dependencies), so the package is findable by what it analyzes.

## 1.0.0

First release.

- `scan`: resolves classes through Composer autoload rules (PSR-4, PSR-0,
  classmap, files), traces packages through `vendor/` and `composer.lock`,
  follows `include` / `require` paths, and adds legacy code with `--source`.
  Writes the pairs document, or snakefood's format with `--format=snakefood`.
- `analyze`: cycles, fan-in, fan-out and instability per file or per cluster
  (`namespace:N`, `folder:N`, `rules:<file>`), with `--include` / `--exclude`.
- `report`: one self-contained HTML page for the whole team, with an
  interactive map; Graphviz DOT with `--format=dot`, and snakefood's black and
  white look with `--style=classic`.
- `--aliases=<preset|file>` resolves global class aliases; `laravel` ships as
  a preset.

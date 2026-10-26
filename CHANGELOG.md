# Changelog

All notable changes to the PHP library. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/): the pairs document, the analysis
document and the command-line options are the public interface.

## Unreleased

## 1.0.2

- The "not covered by composer.json autoload rules" warning no longer counts
  files git ignores (`.gitignore`, nested ones and `.git/info/exclude`), such
  as a Laravel app's compiled views in `storage/framework/views/`, which
  buried the folders that might deserve `--source`. A tracked file still counts,
  and nothing changes when the project is not a git repository or git is not
  installed.

- `$this::X`, `$this::method()` and `$this::$prop` no longer record an
  `unresolved:dynamic:$this` reference: `$this` is the enclosing class, like `static`.

- The "not covered by autoload rules" warning leaves out only the files the repository's own
  rules ignore. The user's global git ignore file no longer hides files (the warning
  was missing on one machine and present in CI), and a project inside a folder that an
  enclosing repository ignores warns as it does elsewhere.

- `dependents` now agrees with the spec and the other libraries: dependents are sorted
  by byte value (`10` before `9`, where PHP's `<=>` put `9` first), and a symbol target
  matches only internal nodes.

- Fixed on Windows: an include with a drive-letter path (`D:/app/vendor/autoload.php`,
  or `D:\app\...` from `__DIR__`) was taken for a relative path, so includes of files
  inside the project came out as `unresolved:include:D:/...`. Drive letters are now
  recognised as absolute, and written in upper case so `d:` and `D:` agree.

- New `dependents` command: lists the files that depend on one or more files
  (or declared symbols), directly or with `--transitive` through other files,
  with each one's depth. It reads a project or a pairs document, accepts
  `--include` / `--exclude`, and writes text grouped by folder or
  `--format=json`. See spec/dependents.md.

- `analyze` and `report` warn on standard error when `--cluster=rules:<file>`
  leaves files unmatched (each becomes its own cluster), naming the first few.
  `--strict` makes that an error: exit code 1 and no output, for CI. Before,
  a new top-level folder that no rule named dropped out of the grouped view
  without a sign.
- `scan` records a class named by an expression (`new $class()`,
  `$class::method()`, `$class::CONST`, `$x instanceof $class`) as
  `unresolved:dynamic:<source text>`, with the edge kind of the construct, as
  the Python library does. Before, these were skipped, so a class only ever
  created from a runtime name could show no dependents.

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

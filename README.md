# Untangled for PHP

The PHP library and CLI of [Untangled](https://github.com/krazybean/Untangled/blob/main/README.md). It scans a Composer
project into the [pairs document](https://github.com/krazybean/Untangled/blob/main/spec/pairs-format.md) defined by the
shared spec, analyzes it for cycles and coupling ([metrics](https://github.com/krazybean/Untangled/blob/main/spec/metrics.md),
[clustering](https://github.com/krazybean/Untangled/blob/main/spec/clustering.md)), and writes a [report](https://github.com/krazybean/Untangled/blob/main/spec/report.md)
anyone on the team can read.

Untangled is a re-imagining of [snakefood](https://github.com/blais/snakefood)
by Martin Blais; the scan → pairs → cluster → render pipeline is his design.
See [Credit](https://github.com/krazybean/Untangled/blob/main/README.md#credit-snakefood).

- [Install](#install)
- [Quick start](#quick-start)
- [Commands](#commands): [`scan`](#scan), [`analyze`](#analyze), [`report`](#report)
- [Options](#options)
- [Recipes](#recipes)
- [What it reads](#what-it-reads)
- [Exit codes and warnings](#exit-codes-and-warnings)
- [Development](#development)

## Install

Requires PHP 8.2+. The only runtime dependency is
[nikic/PHP-Parser](https://github.com/nikic/PHP-Parser).

Install it from Packagist, as a dev dependency of the project you want to
look at, or globally:

```sh
composer require --dev krazybean/untangled
vendor/bin/untangled --version

composer global require krazybean/untangled    # puts `untangled` on your PATH
```

`utg` is a short alias for `untangled`; every example below works with
either. The examples use `vendor/bin/untangled`; with a global install it is
just `untangled`. Untangled reads the project you point it at and never runs its
code, so it does not need to be installed inside that project.

## Quick start

```sh
# A report to share: one HTML file, open it in any browser
vendor/bin/untangled report /path/to/project --cluster=namespace:2 --output=report.html

# The numbers behind it, as JSON
vendor/bin/untangled analyze /path/to/project --cluster=namespace:2

# The raw dependency list
vendor/bin/untangled scan /path/to/project --output=pairs.json
```

The path is the folder that holds the project's `composer.json`. It defaults
to the current folder.

## Commands

```
untangled scan    [<path>]               [scan options] [--format=json|snakefood] [--output=<file>]
untangled analyze [<path>|<pairs.json>]  [scan options] [filter options] [--output=<file>]
untangled report  [<path>|<pairs.json>]  [scan options] [filter options]
                                         [--format=html|dot] [--style=classic] [--title=<text>] [--output=<file>]
```

Each command runs the steps before it: `analyze` scans first, and `report`
scans and analyzes first. `analyze` and `report` also accept a pairs document
saved by `scan`, so a large project can be scanned once and analyzed many
ways. `untangled --help` prints the same summary.

### scan

Writes every file-to-file dependency of the project as a list of
`(from, to)` pairs.

```sh
vendor/bin/untangled scan /path/to/project                          # pairs document (JSON) to stdout
vendor/bin/untangled scan /path/to/project --output=pairs.json      # ... to a file
vendor/bin/untangled scan /path/to/project --dev                    # include autoload-dev (tests)
vendor/bin/untangled scan /path/to/project --format=snakefood       # snakefood's format, for sfood-* tools
```

The default output is the [pairs document](https://github.com/krazybean/Untangled/blob/main/spec/pairs-format.md): one node
per file (with its namespace and the classes it declares) and per package,
builtin or unresolved name it uses, and one edge per dependency with how it
was made (`use`, `extends`, `new`, `include` and so on) and how often. For
example:

```json
{
  "format": "untangled-pairs",
  "language": "php",
  "nodes": [
    { "id": "src/Cart.php", "kind": "internal", "module": "Shop", "symbols": ["Shop\\Cart"] },
    ...
  ],
  "edges": [ ... ]
}
```

`--format=snakefood` writes the same dependencies the way snakefood's
`sfood` did, one Python tuple per line, so existing `sfood-*` scripts can
read them:

```
(('.', 'src/Cart.php'), ('.', 'src/Order.php'))
(('.', 'src/Catalog.php'), (None, None))
```

### analyze

Writes cycles and coupling metrics as JSON (the
[analysis document](https://github.com/krazybean/Untangled/blob/main/spec/metrics.md#the-analysis-document)).

```sh
vendor/bin/untangled analyze /path/to/project                         # per file
vendor/bin/untangled analyze /path/to/project --cluster=namespace:2   # per namespace, e.g. App\Billing
vendor/bin/untangled analyze /path/to/project --cluster=folder:2 --exclude='src/Legacy/**'
vendor/bin/untangled analyze pairs.json --cluster=namespace:2         # from a saved scan
```

It holds:

- **summary**: number of units and dependencies, number of cycles, the
  largest one, how many units sit in a cycle, mean fan-out, and how many
  external packages and unresolved references were found;
- **units**: per file (or per cluster with `--cluster`): fan-in, fan-out,
  instability `I = Ce / (Ca + Ce)`, external and unresolved counts, and `ca` /
  `ce` for clusters;
- **cycles**: every dependency cycle, its members and one example loop, such
  as `src/Cart.php → src/Order.php → src/Cart.php`.

What each number means, and why it matters, is in [metrics](https://github.com/krazybean/Untangled/blob/main/spec/metrics.md).

### report

Writes one self-contained HTML page meant for people who do not read code.
It needs no network access to open and can be emailed or attached to a
ticket as is.

```sh
vendor/bin/untangled report /path/to/project --output=report.html
vendor/bin/untangled report /path/to/project --cluster=namespace:2 --title="Billing" --output=report.html
vendor/bin/untangled report pairs.json --cluster=folder:2 --output=report.html
```

The page has a summary in plain words, why coupling and cycles matter, an
interactive map of the dependencies (capped at the 300 most connected units;
cluster to see the whole project), each cycle written out as a loop, the
parts to look at first, and every metric with a short explanation. The map
can be downloaded from the page as a Graphviz `.dot` file.

`--format=dot` writes that Graphviz file directly, for rendering with `dot`
or pasting into another tool. Units in a cycle are drawn in red.
`--style=classic` draws the black and white look of snakefood's
`sfood-graph` instead (grey ellipses, packages and unresolved names in white,
PHP builtins left out):

```sh
vendor/bin/untangled report /path/to/project --format=dot | dot -Tsvg > graph.svg
vendor/bin/untangled report /path/to/project --format=dot --style=classic --cluster=namespace:2 | dot -Tpng > graph.png
```

## Options

Options are written `--name=value`; a value with spaces needs quotes
(`--title="Billing team"`). Options marked repeatable can be given more than
once.

| Option | Commands | What it does |
| --- | --- | --- |
| `--dev` | all | Also scan the `autoload-dev` rules of `composer.json`, usually the tests. |
| `--source=<path>` | all | Also scan the `.php` files under `<path>` (relative to the project) that `composer.json` does not autoload, such as a legacy `lib/` or a Laravel app's `routes/` and `config/`. The folder is also used to resolve `include` / `require` paths, like PHP's `include_path`. Repeatable. |
| `--aliases=<preset\|file>` | all | Resolve global class aliases a framework registers at runtime (`class_alias`). `laravel` is the built-in preset; a JSON file declares your own. Repeatable, a later one wins. See [Class aliases](#class-aliases). |
| `--format=<format>` | `scan`, `report` | `scan`: `json` (default, the pairs document) or `snakefood`. `report`: `html` (default) or `dot`. |
| `--style=<style>` | `report --format=dot` | `default`, or `classic` for snakefood's black and white `sfood-graph` look. |
| `--title=<text>` | `report` | The report's title. Default: the Composer package name, else the folder or file name. |
| `--cluster=<strategy>` | `analyze`, `report` | Group files before measuring: `namespace:N`, `folder:N` or `rules:<file>`. See [Clustering](#clustering). |
| `--include=<glob>` | `analyze`, `report` | Keep files whose path matches `<glob>`. Repeatable. |
| `--exclude=<glob>` | `analyze`, `report` | Drop files whose path matches `<glob>`. Repeatable. See [Include and exclude](#include-and-exclude). |
| `--output=<file>` | all | Write to `<file>` instead of standard output. |
| `-h`, `--help` | | Print the usage summary. |
| `-V`, `--version` | | Print the version. |

An option a command does not take (`scan --cluster=…`) is an error, not
silently ignored.

### Clustering

Without `--cluster`, every file is its own unit. With it, files are grouped
and the metrics describe the groups, which is usually what a team wants to
look at.

| Strategy | Groups files by | Example |
| --- | --- | --- |
| `namespace:N` | The first `N` segments of the file's namespace. Files with no namespace fall back to `folder:N`. | `namespace:2`: `App\Billing\Tax` → `App\Billing` |
| `folder:N` | The first `N` folders of the file's path. | `folder:2`: `src/Billing/Tax.php` → `src/Billing` |
| `rules:<file>` | Your own prefix rules, read from `<file>`. | see below |

A rules file has one rule per line, a path prefix and a cluster name
separated by spaces; `#` starts a comment. The first prefix that matches a
file wins, and a file no rule matches stays on its own. This is the format of
snakefood's `sfood-cluster`.

```
# prefix              cluster
src/Billing/          Billing
src/Invoice           Billing
src/                  Core
```

```sh
vendor/bin/untangled report /path/to/project --cluster=rules:clusters.rules --output=report.html
```

Packages, builtins and unresolved names are never clustered. The details are
in [clustering](https://github.com/krazybean/Untangled/blob/main/spec/clustering.md).

### Include and exclude

`--include` and `--exclude` take globs matched against the whole file path,
relative to the project (`src/Billing/Tax.php`). `*` matches within one
folder, `?` one character, and `**` any number of folders (`**/` also matches
none, so `**/*Test.php` matches `FooTest.php` at the top level too).

The patterns are checked in the order given and **the last one that matches
wins**; a file no pattern matches is kept. So:

```sh
# Everything except the legacy code
--exclude='src/Legacy/**'

# Only Billing: drop everything, then add Billing back
--exclude='**' --include='src/Billing/**'

# Billing, but not its tests
--exclude='**' --include='src/Billing/**' --exclude='**/*Test.php'
```

Quote globs so the shell does not expand them. Removing a file removes its
dependencies too, and a package or unresolved name nothing remaining uses
disappears from the report.

### Class aliases

Frameworks can register short global names for classes at runtime: in
Laravel, `\DB` means `Illuminate\Support\Facades\DB`. A scanner cannot see
that, so by default such names are reported as unresolved. Declare them with
`--aliases`:

```sh
vendor/bin/untangled scan /path/to/project --aliases=laravel
vendor/bin/untangled scan /path/to/project --aliases=laravel --aliases=my-aliases.json
```

The `laravel` preset holds Laravel's default facade aliases, plus the
aliases installed packages declare under `extra.laravel.aliases` in their
`composer.json`. Your own file looks like this:

```json
{
  "aliases": {
    "DB": "Illuminate\\Support\\Facades\\DB",
    "Money": "App\\Support\\Money"
  }
}
```

When two declarations name the same alias, the later `--aliases` wins.
Aliases nobody declared stay unresolved.

## Recipes

**Scan once, analyze many ways.** Scanning is the slow step on a large project:

```sh
vendor/bin/untangled scan /path/to/project --source=lib --output=pairs.json
vendor/bin/untangled report pairs.json --cluster=namespace:2 --output=by-namespace.html
vendor/bin/untangled report pairs.json --cluster=folder:1 --output=by-folder.html
```

Scan options (`--dev`, `--source`, `--aliases`) only apply when scanning, so
pass them to `scan` in this case.

**A Laravel application.** Routes and config are not autoloaded, and facades
are aliases:

```sh
vendor/bin/untangled report /path/to/app --source=routes --source=config --aliases=laravel \
  --cluster=namespace:2 --output=report.html
```

**A legacy application.** Add the folders its own autoloader or
`include_path` uses; the scanner's warnings name folders with `.php` files it
left out:

```sh
vendor/bin/untangled report /path/to/app --source=lib --source=includes --cluster=folder:2 --output=report.html
```

**A picture for a slide.** Render the classic graph per namespace:

```sh
vendor/bin/untangled report /path/to/project --format=dot --style=classic --cluster=namespace:2 \
  | dot -Tsvg > dependencies.svg
```

**Feed snakefood tools.**

```sh
vendor/bin/untangled scan /path/to/project --format=snakefood | sfood-graph | dot -Tpng > graph.png
```

## What it reads

The full rules are in the [PHP profile](https://github.com/krazybean/Untangled/blob/main/spec/languages/php.md). In short:

- The project must have a `composer.json`. Its `autoload` rules (`psr-4`,
  `psr-0`, `classmap`, `files`) decide which files are scanned; `vendor/` never is.
- Code that Composer does not autoload is added with `--source=<folder>`.
- Global aliases a framework registers at runtime are resolved only when
  declared with `--aliases`.
- `include` / `require` with a literal path (string literals, `__DIR__`,
  `dirname()`) are followed, and the included file is scanned too. Paths
  built at runtime are reported as unresolved.
- Every class reference resolves to a project file, a Composer package (from
  `vendor/composer/installed.json` or `composer.lock`), a PHP builtin, or an
  unresolved node. Builtins are left out of `analyze` and `report`; packages
  and unresolved names are listed but do not count toward the coupling
  metrics.

## Exit codes and warnings

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `1` | The input could not be read: no `composer.json`, an unreadable pairs, rules or aliases file, or an output file that cannot be written. |
| `2` | Usage error: unknown command, option, format, style or clustering strategy. |

Warnings go to standard error and do not change the exit code, so output
piped to a file stays clean. The scanner warns when `.php` files were left
out (naming the folders that hold them, so you can decide what to pass to
`--source`), when there is no `vendor/` or `composer.lock` to trace
packages with or the one it finds is not valid JSON, when it finds no files
to scan at all, and for each file that does not parse. Such a file stays in
the pairs document marked `"error": "parse"`, and the report lists it under
"Files we could not read".

## Development

```sh
composer install
composer test
```

`composer test` runs PHPUnit (a dev dependency in `composer.json`). The suite
has one test file per class under `tests/`, mirroring `src/`, plus
`tests/ConformanceTest.php`, which runs every shared case in
[`conformance/cases`](https://github.com/krazybean/Untangled/blob/main/conformance/README.md). Tests that need files on
disk write a throwaway project with `tests/TempProject.php`.

## License

MIT, see [LICENSE](https://github.com/krazybean/Untangled/blob/main/php/LICENSE).

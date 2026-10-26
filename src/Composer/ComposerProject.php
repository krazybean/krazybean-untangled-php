<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Composer;

use Untangled\Path;

/**
 * A project root with a composer.json: which files its autoload rules cover,
 * and which third-party packages are installed (spec/languages/php.md).
 */
final class ComposerProject
{
    /** @param array<string, mixed> $manifest */
    private function __construct(
        public readonly string $root,
        private readonly array $manifest,
    ) {
    }

    public static function load(string $path): self
    {
        $root = realpath($path);
        if ($root === false || !is_dir($root)) {
            throw new ProjectException("Project directory not found: {$path}");
        }
        $root = Path::normalize($root);
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            throw new ProjectException("No composer.json in {$path}. Untangled needs a Composer project to resolve dependencies.");
        }
        try {
            $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProjectException("composer.json is not valid JSON: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($manifest)) {
            throw new ProjectException('composer.json must contain a JSON object.');
        }
        return new self($root, $manifest);
    }

    /** The vendor directory, relative to the root. */
    public function vendorDir(): string
    {
        $dir = $this->manifest['config']['vendor-dir'] ?? 'vendor';
        return Path::normalize(is_string($dir) ? $dir : 'vendor');
    }

    /**
     * Project-relative paths of every file the root package's autoload rules cover, plus the
     * PHP files under each of $sources (project-relative paths, read like classmap entries), sorted.
     *
     * @param list<string> $sources
     * @return list<string>
     */
    public function sourceFiles(bool $dev, array $sources = []): array
    {
        $sections = [$this->manifest['autoload'] ?? []];
        if ($dev) {
            $sections[] = $this->manifest['autoload-dev'] ?? [];
        }

        $excludes = $this->excludes($sections);
        $files = [];
        foreach ($sections as $autoload) {
            foreach (['psr-4', 'psr-0'] as $standard) {
                foreach ((array) ($autoload[$standard] ?? []) as $dirs) {
                    foreach ((array) $dirs as $dir) {
                        $this->collect((string) $dir, ['php'], $excludes, $files);
                    }
                }
            }
            foreach ((array) ($autoload['classmap'] ?? []) as $entry) {
                $this->collect((string) $entry, ['php', 'inc'], $excludes, $files);
            }
            foreach ((array) ($autoload['files'] ?? []) as $entry) {
                $relative = Path::normalize((string) $entry);
                if (is_file($this->root . '/' . $relative)) {
                    $files[$relative] = true;
                }
            }
        }
        foreach ($sources as $source) {
            $this->collect($source, ['php', 'inc'], $excludes, $files);
        }

        $files = array_keys($files);
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * The `.php` files under the root that are not in $covered, skipping vendor/, node_modules/,
     * hidden directories, `exclude-from-classmap` matches and files git ignores (compiled views,
     * caches): code that no autoload rule reaches and none deliberately leaves out, sorted.
     *
     * @param array<string, true> $covered project-relative paths
     * @return list<string>
     */
    public function uncoveredFiles(array $covered): array
    {
        $all = [];
        $this->collect('', ['php'], $this->excludes([$this->manifest['autoload'] ?? [], $this->manifest['autoload-dev'] ?? []]), $all, ['node_modules']);
        $files = array_keys(array_diff_key($all, $covered));
        $files = array_values(array_diff($files, $this->gitIgnored($files)));
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * The paths git ignores, by asking `git check-ignore` so that nested .gitignore files,
     * .git/info/exclude and negations all count. A tracked file is never ignored. Empty when
     * the root is not in a git repository or git cannot be run: nothing is hidden then.
     *
     * Only the project's own rules count: the user's global ignore file belongs to one machine,
     * and a folder an enclosing repository ignores is no reason to hide what is inside the
     * project, so a project root that git ignores hides nothing.
     *
     * @param list<string> $paths project-relative
     * @return list<string>
     */
    private function gitIgnored(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        // A root with a .git is the top of its own repository; otherwise any ignoring is from above.
        $parent = dirname($this->root);
        if (!file_exists($this->root . '/.git') && $parent !== $this->root && self::checkIgnore($parent, [basename($this->root)]) !== []) {
            return [];
        }
        return self::checkIgnore($this->root, $paths);
    }

    /**
     * Runs `git check-ignore` in $directory on paths relative to it. Empty when git says none is
     * ignored, and also when it cannot tell (exit 128: not a repository, or a path beyond a
     * symbolic link): one such path then brings the ignored files back into the count, which
     * only ever shows more warnings, never fewer.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function checkIgnore(string $directory, array $paths): array
    {
        if (!function_exists('proc_open')) {
            return [];
        }
        // The paths go in through a file, not a pipe, so that git never waits on us to read its output.
        $input = tmpfile();
        if ($input === false) {
            return [];
        }
        fwrite($input, implode("\0", $paths) . "\0");
        rewind($input);
        $process = @proc_open(
            // An empty core.excludesFile leaves out the user's global ignore file (also the default one).
            ['git', '-c', 'core.excludesFile=', 'check-ignore', '--stdin', '-z'],
            [0 => $input, 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
            $pipes,
            $directory,
        );
        if (!is_resource($process)) {
            fclose($input);
            return [];
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($input);
        // 0: some are ignored, 1: none are; anything else means we cannot tell.
        $code = proc_close($process);
        return $code === 0 ? array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== '')) : [];
    }

    /** Whether installed packages can be read, from vendor/composer/installed.json or composer.lock. */
    public function hasPackageList(): bool
    {
        return is_file($this->root . '/' . $this->vendorDir() . '/composer/installed.json') || is_file($this->root . '/composer.lock');
    }

    /**
     * Why the package list cannot be read, or null when it can or there is none: a
     * vendor/composer/installed.json or composer.lock that is not a JSON object.
     */
    public function packageListError(): ?string
    {
        $installed = $this->vendorDir() . '/composer/installed.json';
        $file = is_file($this->root . '/' . $installed) ? $installed : 'composer.lock';
        if (!is_file($this->root . '/' . $file)) {
            return null;
        }
        $text = @file_get_contents($this->root . '/' . $file);
        if ($text === false) {
            return "{$file}: cannot read it";
        }
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return "{$file}: not valid JSON ({$e->getMessage()})";
        }
        return is_array($data) ? null : "{$file}: not a JSON object";
    }

    /** Whether composer.json requires any package (platform requirements such as php and ext-* aside). */
    public function requiresPackages(): bool
    {
        foreach (array_keys((array) ($this->manifest['require'] ?? [])) as $name) {
            if (str_contains((string) $name, '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Installed third-party packages, from vendor/composer/installed.json or, without
     * a vendor directory, from composer.lock.
     *
     * @return list<Package>
     */
    public function packages(): array
    {
        $vendor = $this->root . '/' . $this->vendorDir();
        $installed = $vendor . '/composer/installed.json';
        $entries = [];
        $fromVendor = false;
        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $entries = is_array($data) ? ($data['packages'] ?? $data) : [];
            $fromVendor = true;
        } elseif (is_file($this->root . '/composer.lock')) {
            $data = json_decode((string) file_get_contents($this->root . '/composer.lock'), true);
            if (is_array($data)) {
                $entries = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);
            }
        }

        $packages = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                continue;
            }
            $installPath = null;
            if ($fromVendor) {
                $installPath = isset($entry['install-path'])
                    ? Path::join($vendor . '/composer', (string) $entry['install-path'])
                    : $vendor . '/' . $entry['name'];
            }
            $packages[] = new Package($entry['name'], (array) ($entry['autoload'] ?? []), $installPath, (array) ($entry['extra'] ?? []));
        }
        usort($packages, static fn (Package $a, Package $b): int => strcmp($a->name, $b->name));
        return $packages;
    }

    /**
     * The `exclude-from-classmap` patterns of the given autoload sections, as regexes.
     * @param list<mixed> $sections
     * @return list<string>
     */
    private function excludes(array $sections): array
    {
        $excludes = [];
        foreach ($sections as $autoload) {
            foreach ((array) (is_array($autoload) ? ($autoload['exclude-from-classmap'] ?? []) : []) as $pattern) {
                $excludes[] = self::excludeRegex((string) $pattern);
            }
        }
        return $excludes;
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $excludes
     * @param array<string, true> $files
     * @param list<string> $skipDirectories directory names never descended into, besides vendor/ and hidden ones
     */
    private function collect(string $entry, array $extensions, array $excludes, array &$files, array $skipDirectories = []): void
    {
        $relative = Path::normalize($entry);
        $absolute = $relative === '' ? $this->root : $this->root . '/' . $relative;
        $vendor = $this->vendorDir();

        if (is_file($absolute)) {
            $this->accept($relative, $excludes, $files);
            return;
        }
        if (!is_dir($absolute)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                function (\SplFileInfo $file) use ($vendor, $skipDirectories): bool {
                    if (!$file->isDir()) {
                        return true;
                    }
                    $path = Path::relative($this->root, $file->getPathname());
                    // Never descend into vendor/ or hidden directories such as .git.
                    return $path !== $vendor && !str_starts_with($file->getFilename(), '.')
                        && !in_array($file->getFilename(), $skipDirectories, true);
                },
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            // Skip directories that cannot be read (a storage/ owned by the web server, say).
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $path = Path::relative($this->root, $file->getPathname());
                if ($path !== null) {
                    $this->accept($path, $excludes, $files);
                }
            }
        }
    }

    /**
     * @param list<string> $excludes
     * @param array<string, true> $files
     */
    private function accept(string $path, array $excludes, array &$files): void
    {
        $vendor = $this->vendorDir();
        if ($path === $vendor || str_starts_with($path, $vendor . '/')) {
            return;
        }
        foreach ($excludes as $regex) {
            if (preg_match($regex, $path) === 1) {
                return;
            }
        }
        $files[$path] = true;
    }

    /** Composer's exclude-from-classmap semantics: '**' spans directories, '*' does not, prefix match. */
    private static function excludeRegex(string $pattern): string
    {
        $pattern = ltrim(Path::normalize($pattern), '/');
        $quoted = preg_quote($pattern, '{');
        $quoted = str_replace(['\*\*', '\*'], ['.+?', '[^/]+?'], $quoted);
        return '{^' . $quoted . '}';
    }
}

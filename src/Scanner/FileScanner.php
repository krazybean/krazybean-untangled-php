<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** Parses one PHP file into a FileResult. */
final class FileScanner
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function scan(string $absolutePath): FileResult
    {
        $code = @file_get_contents($absolutePath);
        if ($code === false) {
            return FileResult::failed('cannot read file');
        }
        return $this->scanCode($code, $absolutePath);
    }

    public function scanCode(string $code, string $absolutePath): FileResult
    {
        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (Error $e) {
            return FileResult::failed('parse error: ' . $e->getMessage());
        }

        $collector = new ReferenceCollector($code, $absolutePath);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return $collector->result();
    }
}

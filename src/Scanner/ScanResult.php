<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use Untangled\Diagnostic;
use Untangled\Graph\PairsDocument;

final class ScanResult
{
    /**
     * @param list<string> $warnings problems that did not stop the scan, such as parse errors, as text
     * @param list<Diagnostic> $diagnostics the same problems as data (spec/diagnostics.md); callers that
     *                                      build a result from strings can leave it out
     */
    public function __construct(
        public readonly PairsDocument $pairs,
        public readonly array $warnings = [],
        public readonly array $diagnostics = [],
    ) {
    }

    /**
     * A result whose warnings are the messages of the warning diagnostics, in order.
     *
     * @param list<Diagnostic> $diagnostics
     */
    public static function fromDiagnostics(PairsDocument $pairs, array $diagnostics): self
    {
        $warnings = array_values(array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->message,
            array_filter($diagnostics, static fn (Diagnostic $diagnostic): bool => $diagnostic->level === Diagnostic::WARNING),
        ));
        return new self($pairs, $warnings, $diagnostics);
    }
}

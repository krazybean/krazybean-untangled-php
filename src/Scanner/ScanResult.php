<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Scanner;

use Untangled\Graph\PairsDocument;

final class ScanResult
{
    /** @param list<string> $warnings problems that did not stop the scan, such as parse errors */
    public function __construct(
        public readonly PairsDocument $pairs,
        public readonly array $warnings,
    ) {
    }
}

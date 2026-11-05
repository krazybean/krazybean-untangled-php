<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Analysis;

/** A dependents query that cannot be answered, with the diagnostic code of spec/diagnostics.md. */
final class DependentsException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly string $diagnosticCode)
    {
        parent::__construct($message);
    }
}

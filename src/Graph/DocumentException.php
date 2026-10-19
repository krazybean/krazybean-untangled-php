<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Graph;

/** A document that cannot be read: not JSON, the wrong format, or an unsupported version. */
final class DocumentException extends \RuntimeException
{
}

<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Untangled\Composer;

/** The project cannot be scanned: no composer.json, unreadable manifest, missing directory. */
final class ProjectException extends \RuntimeException
{
}

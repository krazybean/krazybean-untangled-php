<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

// Installed as a dependency, the autoloader is three levels up (vendor/krazybean/untangled/bin);
// in a checkout of this repository it is in php/vendor.
foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}
if (!class_exists(Untangled\Cli\Application::class)) {
    fwrite(STDERR, "Untangled's dependencies are not installed. Run `composer install`.\n");
    exit(1);
}

exit((new Untangled\Cli\Application())->run(array_slice($argv, 1)));

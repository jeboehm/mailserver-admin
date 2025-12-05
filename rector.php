<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withRules([
        InlineConstructorDefaultToPropertyRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withImportNames(importShortClasses: false);

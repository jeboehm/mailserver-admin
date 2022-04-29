<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Symfony\Set\SymfonySetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
                             __DIR__.'/src',
                         ]);

    // register a single rule
    $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);

    // define sets of rules
    $rectorConfig->sets([
                            LevelSetList::UP_TO_PHP_80,
                            SymfonySetList::SYMFONY_60,
                            SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES,
                            DoctrineSetList::DOCTRINE_ORM_29,
                            DoctrineSetList::DOCTRINE_DBAL_30,
                            ]);
};

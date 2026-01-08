<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$config = new PhpCsFixer\Config();
$header = <<<EOF
This file is part of the mailserver-admin package.
(c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOF;

return $config
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
                   '@PSR2' => true,
                   '@Symfony' => true,
                   '@autoPHPMigration' => true,
                   'header_comment' => ['header' => $header, 'separate' => 'bottom', 'comment_type' => 'PHPDoc'],
                   'no_useless_else' => true,
                   'no_useless_return' => true,
                   'ordered_class_elements' => true,
                   'ordered_imports' => true,
                   'phpdoc_order' => true,
                   'concat_space' => ['spacing' => 'one'],
                   'array_syntax' => ['syntax' => 'short'],
                   'declare_strict_types' => true,
               ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('vendor')
            ->in(__DIR__ . '/bin')
            ->in(__DIR__ . '/public')
            ->in(__DIR__ . '/tests')
            ->in(__DIR__ . '/src')
            ->in(__DIR__ . '/migrations')
    );

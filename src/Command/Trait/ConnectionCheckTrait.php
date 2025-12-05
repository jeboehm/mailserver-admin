<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command\Trait;

use App\Service\ConnectionCheckService;
use Symfony\Component\Console\Output\OutputInterface;

trait ConnectionCheckTrait
{
    protected function checkConnections(
        ConnectionCheckService $connectionCheckService,
        OutputInterface $output
    ): bool {
        $results = $connectionCheckService->checkAll();
        $hasErrors = false;

        if (null !== $results['mysql']) {
            $hasErrors = true;
            $output->writeln('<fg=red>[ERROR]</> Your MySQL connection failed because of:');
            $output->writeln(sprintf('<fg=red>%s</>', $results['mysql']));
        }

        if (null !== $results['redis']) {
            $hasErrors = true;
            $output->writeln('<fg=red>[ERROR]</> Your Redis connection failed because of:');
            $output->writeln(sprintf('<fg=red>%s</>', $results['redis']));
        }

        return !$hasErrors;
    }
}

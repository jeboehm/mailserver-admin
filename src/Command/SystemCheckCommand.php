<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Service\ConnectionCheckService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemCheckCommand extends Command
{
    public function __construct(
        private readonly ConnectionCheckService $connectionCheckService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('system:check')
            ->setDescription('Check MySQL and Redis connection status.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->connectionCheckService->checkAll();

        $hasErrors = false;

        // Check MySQL
        if (null === $results['mysql']) {
            $output->writeln('<fg=green>[OK]</> MySQL connection is working.');
        } else {
            $hasErrors = true;
            $output->writeln('<fg=red>[ERROR]</> Your MySQL connection failed because of:');
            $output->writeln(sprintf('<fg=red>%s</>', $results['mysql']));
        }

        // Check Redis
        if (null === $results['redis']) {
            $output->writeln('<fg=green>[OK]</> Redis connection is working.');
        } else {
            $hasErrors = true;
            $output->writeln('<fg=red>[ERROR]</> Your Redis connection failed because of:');
            $output->writeln(sprintf('<fg=red>%s</>', $results['redis']));
        }

        return $hasErrors ? 1 : 0;
    }
}

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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'system:check',
    description: 'Check MySQL and Redis connection status.',
)]
class SystemCheckCommand extends Command
{
    public function __construct(
        private readonly ConnectionCheckService $connectionCheckService,
        #[Autowire('%env(string:WAITSTART_TIMEOUT)%')]
        private string $dependencyWaitTimeout,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for dependencies to become available')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Check all services including Doveadm and Rspamd');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $wait = (bool) $input->getOption('wait');
        $all = (bool) $input->getOption('all');

        if ($wait) {
            $timeout = $this->parseTimeout();
            $startTime = time();
            $endTime = $startTime + $timeout;

            $output->writeln(sprintf('Waiting for dependencies to become available (timeout: %ds)...', $timeout));

            while (time() < $endTime) {
                $results = $this->connectionCheckService->checkAll($all);

                $hasErrors = false;
                if (null !== $results['mysql'] || null !== $results['redis']) {
                    $hasErrors = true;
                }

                if ($all && (null !== ($results['doveadm'] ?? null) || null !== ($results['rspamd'] ?? null))) {
                    $hasErrors = true;
                }

                if (!$hasErrors) {
                    $output->writeln('<fg=green>[OK]</> All dependencies are now available.');
                    $output->writeln('<fg=green>[OK]</> MySQL connection is working.');
                    $output->writeln('<fg=green>[OK]</> Redis connection is working.');
                    if ($all) {
                        $output->writeln('<fg=green>[OK]</> Doveadm connection is working.');
                        $output->writeln('<fg=green>[OK]</> Rspamd connection is working.');
                    }

                    return 0;
                }

                sleep(1);
            }

            $output->writeln(sprintf('<fg=red>[ERROR]</> Timeout reached after %ds. Dependencies are still not available.', $timeout));
        }

        $results = $this->connectionCheckService->checkAll($all);

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

        // Check Doveadm (only if --all flag is set)
        if ($all) {
            if (null === ($results['doveadm'] ?? null)) {
                $output->writeln('<fg=green>[OK]</> Doveadm connection is working.');
            } else {
                $hasErrors = true;
                $output->writeln('<fg=red>[ERROR]</> Your Doveadm connection failed because of:');
                $output->writeln(sprintf('<fg=red>%s</>', $results['doveadm']));
            }

            // Check Rspamd
            if (null === ($results['rspamd'] ?? null)) {
                $output->writeln('<fg=green>[OK]</> Rspamd connection is working.');
            } else {
                $hasErrors = true;
                $output->writeln('<fg=red>[ERROR]</> Your Rspamd connection failed because of:');
                $output->writeln(sprintf('<fg=red>%s</>', $results['rspamd']));
            }
        }

        return $hasErrors ? 1 : 0;
    }

    /**
     * Parse timeout from WAITSTART_TIMEOUT environment variable.
     * Supports formats like: 10s, 2m, 10m, 1h
     * Defaults to 60 seconds if not set or invalid.
     *
     * @return int Timeout in seconds
     */
    private function parseTimeout(): int
    {
        if (!preg_match('/^(\d+)([smh])$/', $this->dependencyWaitTimeout, $matches)) {
            return 60;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            default => 60,
        };
    }
}

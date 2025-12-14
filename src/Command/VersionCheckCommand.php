<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Service\ApplicationVersionService;
use App\Service\GitHubTagService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VersionCheckCommand extends Command
{
    public function __construct(
        private readonly ApplicationVersionService $applicationVersionService,
        private readonly GitHubTagService $gitHubTagService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('version:check')
            ->setDescription('Check current versions against latest GitHub releases.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Checking versions...</info>');
        $output->writeln('');

        // Get current versions
        $currentAdminVersion = $this->applicationVersionService->getAdminVersion();
        $currentMailserverVersion = $this->applicationVersionService->getMailserverVersion();
        $errors = [];

        $latestAdminVersion = $this->gitHubTagService->getLatestTag('jeboehm', 'mailserver-admin');
        $latestMailserverVersion = $this->gitHubTagService->getLatestTag('jeboehm', 'docker-mailserver');

        // Create and display table
        $table = new Table($output);
        $table->setHeaders(['Component', 'Current Version', 'Latest Version', 'Status']);

        // Add admin row
        $adminStatus = $this->getStatus($currentAdminVersion, $latestAdminVersion);
        $table->addRow([
            'mailserver-admin',
            $currentAdminVersion ?? '<fg=yellow>Not found</>',
            $latestAdminVersion ?? '<fg=red>Error</>',
            $adminStatus,
        ]);

        // Add mailserver row
        $mailserverStatus = $this->getStatus($currentMailserverVersion, $latestMailserverVersion);
        $table->addRow([
            'docker-mailserver',
            $currentMailserverVersion ?? '<fg=yellow>Not found</>',
            $latestMailserverVersion ?? '<fg=red>Error</>',
            $mailserverStatus,
        ]);

        $table->render();

        // Return exit code based on status
        $hasOutdated = (null !== $currentAdminVersion && null !== $latestAdminVersion && $currentAdminVersion !== $latestAdminVersion)
            || (null !== $currentMailserverVersion && null !== $latestMailserverVersion && $currentMailserverVersion !== $latestMailserverVersion);

        return $hasOutdated ? 1 : 0;
    }

    private function getStatus(?string $current, ?string $latest): string
    {
        if (null === $current) {
            return '<fg=yellow>Unknown</>';
        }

        if (null === $latest) {
            return '<fg=red>Error</>';
        }

        if ($current === $latest) {
            return '<fg=green>Up to date</>';
        }

        return '<fg=yellow>Outdated</>';
    }
}

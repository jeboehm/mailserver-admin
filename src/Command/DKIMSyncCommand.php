<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Service\DKIM\Config\Manager;
use App\Service\FetchmailAccount\AccountWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DKIMSyncCommand extends Command
{
    public function __construct(
        private readonly Manager $manager,
        private readonly AccountWriter $accountWriter,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('dkim:refresh')
            ->setDescription('Updates the DKIM configuration folder.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->manager->refresh();
        $this->accountWriter->write();

        return 0;
    }
}

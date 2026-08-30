<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Command\Trait\ConnectionCheckTrait;
use App\Service\ConnectionCheckService;
use App\Service\DKIM\Config\Manager;
use App\Service\FetchmailAccount\AccountWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'redis:sync', description: 'Persist DKIM data and fetchmail accounts to redis.', aliases: ['dkim:refresh'])]
class RedisSyncCommand extends Command
{
    use ConnectionCheckTrait;

    public function __construct(
        private readonly Manager $manager,
        private readonly AccountWriter $accountWriter,
        private readonly ConnectionCheckService $connectionCheckService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->checkConnections($this->connectionCheckService, $output)) {
            return Command::FAILURE;
        }

        $this->manager->refresh();
        $this->accountWriter->write();

        return Command::SUCCESS;
    }
}

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
use App\Repository\DomainRepository;
use App\Service\ConnectionCheckService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'dkim:disable', description: 'Disables DKIM for a specific domain.')]
class DKIMDisableCommand extends Command
{
    use ConnectionCheckTrait;

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly DomainRepository $domainRepository,
        private readonly ConnectionCheckService $connectionCheckService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain-part (after @)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->checkConnections($this->connectionCheckService, $output)) {
            return Command::FAILURE;
        }

        $name = mb_strtolower($input->getArgument('domain'));
        $domain = $this->domainRepository->findOneBy(['name' => $name]);

        if (null === $domain) {
            $output->writeln(\sprintf('<error>Domain "%s" was not found.</error>', $name));

            return Command::FAILURE;
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $result = $questionHelper->ask(
            $input,
            $output,
            new ConfirmationQuestion(\sprintf('Do you want to disable DKIM for domain "%s"?', $domain->getName()))
        );

        if (!$result) {
            $output->writeln('Aborting.');

            return Command::FAILURE;
        }

        $domain->setDkimEnabled(false);

        $this->manager->flush();

        $output->writeln('Done.');

        return Command::SUCCESS;
    }
}

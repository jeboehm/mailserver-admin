<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Entity\Domain;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DKIMDisableCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $manager)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('dkim:disable')
            ->setDescription('Disables DKIM for a specific domain.')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain-part (after @)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('domain');
        $domain = $this->manager->getRepository(Domain::class)->findOneBy(['name' => $name]);

        if (null === $domain) {
            $output->writeln(sprintf('<error>Domain "%s" was not found.</error>', $name));

            return 1;
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $result = $questionHelper->ask(
            $input,
            $output,
            new ConfirmationQuestion(sprintf('Do you want to disable DKIM for domain "%s"?', $domain->getName()))
        );

        if (!$result) {
            $output->writeln('Aborting.');

            return 1;
        }

        $domain->setDkimEnabled(false);

        $this->manager->getManager()->flush();

        $output->writeln('Done.');

        return 0;
    }
}

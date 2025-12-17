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
use App\Entity\Alias;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\ConnectionCheckService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AliasAddCommand extends Command
{
    use ConnectionCheckTrait;

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly DomainRepository $domainRepository,
        private readonly ValidatorInterface $validator,
        private readonly ConnectionCheckService $connectionCheckService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('alias:add')
            ->setDescription('Add aliases.')
            ->addOption('catchall', null, InputOption::VALUE_NONE, 'Catch all mails to this domain.')
            ->addArgument('from', InputArgument::REQUIRED, 'Address of the new alias.')
            ->addArgument('to', InputArgument::REQUIRED, 'Where mails to the new alias go to.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->checkConnections($this->connectionCheckService, $output)) {
            return 1;
        }

        $from = $input->getArgument('from');

        if (!$input->getOption('catchall')) {
            $from = \filter_var($from, \FILTER_VALIDATE_EMAIL);

            if (!$from) {
                $output->writeln(
                    sprintf('<error>%s is not a valid email address.</error>', $input->getArgument('from'))
                );

                return 1;
            }
        }

        $to = \filter_var($input->getArgument('to'), \FILTER_VALIDATE_EMAIL);

        if (!$to) {
            $output->writeln(sprintf('<error>%s is not a valid email address.</error>', $input->getArgument('to')));

            return 1;
        }

        $alias = new Alias();
        $alias->setDestination($to);

        $fromParts = \explode('@', (string) $from, 2);

        if (2 !== count($fromParts)) {
            $output->writeln(sprintf('<error>%s is not a valid email address.</error>', $input->getArgument('from')));

            return 1;
        }

        $domain = $this->getDomain($fromParts[1]);

        if (null === $domain) {
            $output->writeln(sprintf('<error>Domain %s has to be created before.</error>', $fromParts[1]));

            return 1;
        }

        $alias->setDomain($domain);
        $alias->setName(\mb_strtolower($fromParts[0]));

        $validationResult = $this->validator->validate($alias);

        if ($validationResult->count() > 0) {
            foreach ($validationResult as $item) {
                /* @var $item ConstraintViolation */
                $output->writeln(sprintf('<error>%s: %s</error>', $item->getPropertyPath(), $item->getMessage()));
            }

            return 1;
        }

        $this->manager->persist($alias);
        $this->manager->flush();

        return 0;
    }

    private function getDomain(string $domain): ?Domain
    {
        return $this->domainRepository->findOneBy(['name' => \mb_strtolower($domain)]);
    }
}

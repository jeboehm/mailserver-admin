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
use App\Entity\Domain;
use App\Service\ConnectionCheckService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'domain:add', description: 'Add domains.')]
class DomainAddCommand extends Command
{
    use ConnectionCheckTrait;

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly ValidatorInterface $validator,
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

        $domain = new Domain();
        $domain->setName(mb_strtolower((string) $input->getArgument('domain')));

        $validationResult = $this->validator->validate($domain);

        if ($validationResult->count() > 0) {
            foreach ($validationResult as $item) {
                /* @var $item ConstraintViolation */
                $output->writeln(\sprintf('<error>%s: %s</error>', $item->getPropertyPath(), $item->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->manager->persist($domain);
        $this->manager->flush();

        return Command::SUCCESS;
    }
}

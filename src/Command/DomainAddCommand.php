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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DomainAddCommand extends Command
{
    private $manager;

    private $validator;

    public function __construct(
        string $name = null,
        EntityManagerInterface $manager,
        ValidatorInterface $validator
    ) {
        parent::__construct($name);

        $this->manager = $manager;
        $this->validator = $validator;
    }

    protected function configure(): void
    {
        $this
            ->setName('domain:add')
            ->setDescription('Add domains.')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain-part (after @)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = new Domain();
        $domain->setName(\mb_strtolower($input->getArgument('domain')));

        $validationResult = $this->validator->validate($domain);

        if ($validationResult->count() > 0) {
            foreach ($validationResult as $item) {
                /* @var $item ConstraintViolation */
                $output->writeln(sprintf('<error>%s: %s</error>', $item->getPropertyPath(), $item->getMessage()));
            }

            return 1;
        }

        $this->manager->persist($domain);
        $this->manager->flush();

        return 0;
    }
}

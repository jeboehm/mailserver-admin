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
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Service\ConnectionCheckService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'user:add', description: 'Add users.')]
class UserAddCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED, 'Local-part (before @)')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain-part (after @), has to be created already')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Allow login to management interface')
            ->addOption('sendonly', null, InputOption::VALUE_NONE, 'Send only accounts cannot receive mails')
            ->addOption('quota', null, InputOption::VALUE_REQUIRED, 'Limit the disk usage of this account')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Sets the account password directly')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the new created account');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->checkConnections($this->connectionCheckService, $output)) {
            return Command::FAILURE;
        }

        $user = new User();
        $domain = $this->getDomain($input->getArgument('domain'));

        if (null === $domain) {
            $output->writeln(\sprintf('<error>Domain %s was not found.</error>', $input->getArgument('domain')));

            return Command::FAILURE;
        }

        $user->setDomain($domain);
        $user->setName(mb_strtolower((string) $input->getArgument('name')));
        $user->setAdmin((bool) $input->getOption('admin'));
        $user->setSendOnly((bool) $input->getOption('sendonly'));
        $user->setEnabled((bool) $input->getOption('enable'));

        if ($input->hasOption('quota')) {
            $user->setQuota((int) $input->getOption('quota'));
        }

        if (!$input->getOption('password')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('Password? (hidden)');
            $question->setHidden(true);

            $password = (string) $helper->ask($input, $output, $question);

            if ('' === $password) {
                $output->writeln('<error>Please set a valid password.</error>');

                return Command::FAILURE;
            }

            $user->setPlainPassword($password);
        } else {
            $user->setPlainPassword($input->getOption('password'));
        }

        $validationResult = $this->validator->validate($user);

        if ($validationResult->count() > 0) {
            foreach ($validationResult as $item) {
                /* @var $item ConstraintViolation */
                $output->writeln(\sprintf('<error>%s: %s</error>', $item->getPropertyPath(), $item->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->manager->persist($user);
        $this->manager->flush();

        return Command::SUCCESS;
    }

    private function getDomain(string $domain): ?Domain
    {
        return $this->domainRepository->findOneBy(['name' => mb_strtolower($domain)]);
    }
}

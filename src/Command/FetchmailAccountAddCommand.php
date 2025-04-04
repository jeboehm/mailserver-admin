<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Entity\FetchmailAccount;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FetchmailAccountAddCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('fetchmail:account:add')
            ->setDescription('Add fetchmail account.')
            ->addArgument('user', InputArgument::REQUIRED, 'User to fetch mails for (user@domain)')
            ->addArgument('host', InputArgument::REQUIRED, 'Host to fetch mails from')
            ->addArgument('protocol', InputArgument::REQUIRED, 'Protocol, either imap or pop3')
            ->addArgument('port', InputArgument::REQUIRED, 'Port to connect to')
            ->addArgument('username', InputArgument::REQUIRED, 'Username to log in to the remote host')
            ->addArgument('password', InputArgument::REQUIRED, 'Password to log in to the remote host')
            ->addOption('ssl', null, InputOption::VALUE_NONE, 'Use SSL to connect to the remote host')
            ->addOption('verify-ssl', null, InputOption::VALUE_NONE, 'Verify the SSL certificate of the remote host')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force the command to run despite any validation issues');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->userRepository->findOneByEmailAddress(
            $input->getArgument('user'),
        );

        if (null === $user) {
            $output->writeln(sprintf('<error>User %s not found.</error>', $input->getArgument('user')));

            return self::FAILURE;
        }

        $fetchmailAccount = new FetchmailAccount();
        $fetchmailAccount->setUser($user);
        $fetchmailAccount->setHost($input->getArgument('host'));
        $fetchmailAccount->setProtocol($input->getArgument('protocol'));
        $fetchmailAccount->setPort((int) $input->getArgument('port'));
        $fetchmailAccount->setUsername($input->getArgument('username'));
        $fetchmailAccount->setPassword($input->getArgument('password'));
        $fetchmailAccount->setSsl($input->getOption('ssl'));
        $fetchmailAccount->setVerifySsl($input->getOption('verify-ssl'));

        $errors = $this->validator->validate($fetchmailAccount);

        if (count($errors) > 0) {
            foreach ($errors as $error) {
                if ($error instanceof ConstraintViolationInterface) {
                    $output->writeln(sprintf('<error>%s</error>', $error->getMessage()));
                }
            }

            if ($input->getOption('force')) {
                $output->writeln('<info>Forcing command to run despite validation issues.</info>');
            } else {
                $output->writeln('<error>Validation failed. Aborting.</error>');

                return self::FAILURE;
            }
        }

        $this->entityManager->persist($fetchmailAccount);
        $this->entityManager->flush();

        return self::SUCCESS;
    }
}

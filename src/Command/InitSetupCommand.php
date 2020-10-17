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
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InitSetupCommand extends Command
{
    private ValidatorInterface $validator;
    private ManagerRegistry $manager;

    public function __construct(ValidatorInterface $validator, ManagerRegistry $manager)
    {
        parent::__construct();

        $this->validator = $validator;
        $this->manager = $manager;
    }

    protected function configure(): void
    {
        $this
            ->setName('init:setup')
            ->setDescription('Does an initially setup for docker-mailserver.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $output
            ->writeln(
                [
                    '<info>Welcome to docker-mailserver!</info>',
                    '<info>This tool will help you to set up the first mail account.</info>',
                    '<info>You just have to answer a few questions.</info>',
                ]
            );

        [$localPart, $domainPart] = $this->getEmailAddress($questionHelper, $input, $output);
        $password = $this->getPassword($questionHelper, $input, $output);

        $domain = new Domain();
        $domain->setName($domainPart);

        $user = new User();
        $user->setName($localPart);
        $user->setPlainPassword($password);
        $user->setAdmin(true);
        $user->setDomain($domain);

        $domainValidationList = $this->validator->validate($domain);
        $userValidationList = $this->validator->validate($user);

        if ($domainValidationList->count() > 0) {
            foreach ($domainValidationList as $item) {
                /* @var $item ConstraintViolation */
                $output->writeln(
                    sprintf('<error>Domain %s: %s</error>', $item->getPropertyPath(), $item->getMessage())
                );
            }

            $output->writeln('<error>There were some errors. Please start over again.</error>');

            return 1;
        }

        if ($userValidationList->count() > 0) {
            foreach ($userValidationList as $item) {
                /* @var $item ConstraintViolation */
                $output->writeln(
                    sprintf('<error>User %s: %s</error>', $item->getPropertyPath(), $item->getMessage())
                );
            }

            $output->writeln('<error>There were some errors. Please start over again.</error>');

            return 1;
        }

        $this->manager->getManager()->persist($domain);
        $this->manager->getManager()->persist($user);

        $this->manager->getManager()->flush();

        $output->writeln(sprintf('<info>Your new email address %s was successfully created.</info>', $user));
        $output->writeln('<info>You can now login using the previously set password.</info>');

        return 0;
    }

    private function getEmailAddress(
        QuestionHelper $questionHelper,
        InputInterface $input,
        OutputInterface $output
    ): array {
        $emailQuestion = new Question('Please enter the first email address you want to receive mails to: ');
        $emailQuestion->setValidator(
            function (string $value): string {
                if (!$value = \filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid email address.');
                }

                return $value;
            }
        );

        $email = (string) $questionHelper->ask(
            $input,
            $output,
            $emailQuestion
        );

        return \explode('@', $email);
    }

    private function getPassword(
        QuestionHelper $questionHelper,
        InputInterface $input,
        OutputInterface $output
    ): string {
        $passwordQuestion = new Question('Enter a password for the new account: ');
        $passwordQuestion->setValidator(
            function (string $value): string {
                if (\mb_strlen($value) < 8) {
                    throw new RuntimeException('The password should be longer.');
                }

                return $value;
            }
        );
        $passwordQuestion->setHidden(true);

        $repeatPasswordQuestion = new Question('Repeat the password: ');
        $repeatPasswordQuestion->setHidden(true);

        $password1 = $questionHelper->ask($input, $output, $passwordQuestion);
        $password2 = $questionHelper->ask($input, $output, $repeatPasswordQuestion);

        if ($password1 !== $password2) {
            $output->writeln('<error>The passwords do not match.</error>');

            return $this->getPassword($questionHelper, $input, $output);
        }

        return (string) $password1;
    }
}

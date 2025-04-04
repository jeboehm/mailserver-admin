<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\FetchmailAccountAddCommand;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FetchmailAccountAddCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&UserRepository $userRepository;
    private MockObject&ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->validator = $this->createMock(ValidatorInterface::class);

        $application = new Application();
        $application->add(
            new FetchmailAccountAddCommand(
                $this->userRepository,
                $this->validator,
                $this->entityManager,
            )
        );

        $this->commandTester = new CommandTester($application->find('fetchmail:account:add'));
    }

    public function testCreateAccount(): void
    {
        $this->userRepository->expects($this->once())->method('findOneByEmailAddress')->willReturn($this->createMock(User::class));
        $this->validator->expects($this->once())->method('validate')->willReturn(new ConstraintViolationList());
        $this->entityManager->expects($this->once())->method('persist')->with(
            $this->callback(function (FetchmailAccount $fetchmailAccount) {
                self::assertEquals('example.com', $fetchmailAccount->getHost());
                self::assertEquals('imap', $fetchmailAccount->getProtocol());
                self::assertEquals(993, $fetchmailAccount->getPort());
                self::assertEquals('test@example.com', $fetchmailAccount->getUsername());
                self::assertEquals('changeme', $fetchmailAccount->getPassword());
                self::assertTrue($fetchmailAccount->isSsl());
                self::assertTrue($fetchmailAccount->isVerifySsl());

                return true;
            })
        );
        $this->entityManager->expects($this->once())->method('flush');

        $data = [
            'user' => 'admin@example.com',
            'host' => 'example.com',
            'protocol' => 'imap',
            'port' => 993,
            'username' => 'test@example.com',
            'password' => 'changeme',
            '--ssl' => true,
            '--verify-ssl' => true,
        ];

        $this->commandTester->execute($data);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testCreateAccountWithValidationError(): void
    {
        $this->userRepository->expects($this->once())->method('findOneByEmailAddress')->willReturn($this->createMock(User::class));
        $this->validator->expects($this->once())->method('validate')->willReturn(new ConstraintViolationList(
            [
                new ConstraintViolation('test', 'test', [], '', '', ''),
            ]
        ));
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $data = [
            'user' => 'admin@example.com',
            'host' => 'example.com',
            'protocol' => 'imap',
            'port' => 993,
            'username' => 'test@example.com',
            'password' => 'changeme',
            '--ssl' => true,
            '--verify-ssl' => true,
        ];

        $this->commandTester->execute($data);

        self::assertEquals(Command::FAILURE, $this->commandTester->getStatusCode());
    }

    public function testCreateAccountWithForce(): void
    {
        $this->userRepository->expects($this->once())->method('findOneByEmailAddress')->willReturn($this->createMock(User::class));
        $this->validator->expects($this->once())->method('validate')->willReturn(new ConstraintViolationList(
            [
                new ConstraintViolation('test', 'test', [], '', '', ''),
            ]
        ));
        $this->entityManager->expects($this->once())->method('persist')->with(
            $this->callback(function (FetchmailAccount $fetchmailAccount) {
                self::assertEquals('example.com', $fetchmailAccount->getHost());
                self::assertEquals('imap', $fetchmailAccount->getProtocol());
                self::assertEquals(993, $fetchmailAccount->getPort());
                self::assertEquals('test@example.com', $fetchmailAccount->getUsername());
                self::assertEquals('changeme', $fetchmailAccount->getPassword());
                self::assertTrue($fetchmailAccount->isSsl());
                self::assertTrue($fetchmailAccount->isVerifySsl());

                return true;
            })
        );
        $this->entityManager->expects($this->once())->method('flush');

        $data = [
            'user' => 'admin@example.com',
            'host' => 'example.com',
            'protocol' => 'imap',
            'port' => 993,
            'username' => 'test@example.com',
            'password' => 'changeme',
            '--ssl' => true,
            '--verify-ssl' => true,
            '--force' => true,
        ];

        $this->commandTester->execute($data);

        self::assertEquals(Command::SUCCESS, $this->commandTester->getStatusCode());
    }
}

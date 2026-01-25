<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\InitSetupCommand;
use App\Entity\Domain;
use App\Entity\User;
use App\Service\ConnectionCheckService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InitSetupCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject&EntityManagerInterface $managerMock;

    private MockObject&ValidatorInterface $validatorMock;

    private MockObject&ConnectionCheckService $connectionCheckServiceMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(EntityManagerInterface::class);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);

        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => null, 'redis' => null]);

        $application = new Application();
        $application->addCommand(new InitSetupCommand($this->validatorMock, $this->managerMock, $this->connectionCheckServiceMock));

        $this->commandTester = new CommandTester($application->find('init:setup'));
    }

    public function testExecuteSuccess(): void
    {
        $violationList = $this->createStub(ConstraintViolationListInterface::class);
        $violationList->method('count')->willReturn(0);

        $this->validatorMock
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->willReturn($violationList);

        $this->managerMock->expects($this->once())->method('flush');
        $matcher = $this->exactly(2);
        $this->managerMock
            ->expects($matcher)
            ->method('persist')->willReturnCallback(function (...$parameters) use ($matcher) {
                if (1 === $matcher->numberOfInvocations()) {
                    $callback = function (Domain $domain) {
                        $this->assertEquals('example.com', $domain->getName());

                        return true;
                    };
                    $this->assertTrue($callback($parameters[0]));
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $callback = function (User $user) {
                        $this->assertEquals('jeff', $user->getName());
                        $this->assertEquals('123456789', $user->getPlainPassword());
                        $this->assertTrue($user->isAdmin());
                        $this->assertEquals('example.com', $user->getDomain()?->getName());

                        return true;
                    };
                    $this->assertTrue($callback($parameters[0]));
                }
            });

        $this->commandTester->setInputs([
            'jeff@example.com',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Welcome to docker-mailserver!', $output);
        $this->assertStringContainsString('Your new email address jeff@example.com was successfully created.', $output);
    }

    public function testExecuteConnectionCheckFailure(): void
    {
        $this->validatorMock->expects($this->never())->method('validate');
        $this->connectionCheckServiceMock->checkAll(); // to fulfill the requirement in setUp()
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);
        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => 'Connection refused', 'redis' => null]);

        $application = new Application();
        $application->addCommand(new InitSetupCommand($this->validatorMock, $this->managerMock, $this->connectionCheckServiceMock));
        $this->commandTester = new CommandTester($application->find('init:setup'));

        $this->managerMock->expects($this->never())->method('persist');
        $this->managerMock->expects($this->never())->method('flush');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteInvalidEmailAddress(): void
    {
        $this->validatorMock->expects($this->atLeastOnce())->method('validate');
        $this->managerMock->expects($this->atLeastOnce())->method('persist');
        $this->managerMock->expects($this->once())->method('flush');

        $this->commandTester->setInputs([
            'invalid-email',
            'jeff@example.com',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Please enter a valid email address.', $output);
    }

    public function testExecutePasswordTooShort(): void
    {
        $this->validatorMock->expects($this->atLeastOnce())->method('validate');
        $this->managerMock->expects($this->atLeastOnce())->method('persist');
        $this->managerMock->expects($this->once())->method('flush');

        $this->commandTester->setInputs([
            'jeff@example.com',
            'short',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('The password should be longer.', $output);
    }

    public function testExecutePasswordMismatch(): void
    {
        $this->validatorMock->expects($this->atLeastOnce())->method('validate');
        $this->managerMock->expects($this->atLeastOnce())->method('persist');
        $this->managerMock->expects($this->once())->method('flush');

        $this->commandTester->setInputs([
            'jeff@example.com',
            '123456789',
            'different',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('The passwords do not match.', $output);
    }

    public function testExecuteDomainValidationFailure(): void
    {
        $violationList = new ConstraintViolationList();
        $violationList->add(new ConstraintViolation('Domain name is invalid', null, [], null, 'name', 'example.com'));

        $emptyViolationList = $this->createStub(ConstraintViolationListInterface::class);
        $emptyViolationList->method('count')->willReturn(0);

        $this->validatorMock
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->willReturnCallback(static function ($entity) use ($violationList, $emptyViolationList) {
                if ($entity instanceof Domain) {
                    return $violationList;
                }

                return $emptyViolationList;
            });

        $this->managerMock->expects($this->never())->method('persist');
        $this->managerMock->expects($this->never())->method('flush');

        $this->commandTester->setInputs([
            'jeff@example.com',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Domain name: Domain name is invalid', $output);
        $this->assertStringContainsString('There were some errors. Please start over again.', $output);
    }

    public function testExecuteUserValidationFailure(): void
    {
        $violationList = new ConstraintViolationList();
        $violationList->add(new ConstraintViolation('User name is invalid', null, [], null, 'name', 'jeff'));

        $emptyViolationList = $this->createStub(ConstraintViolationListInterface::class);
        $emptyViolationList->method('count')->willReturn(0);

        $this->validatorMock
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->willReturnCallback(static function ($entity) use ($violationList, $emptyViolationList) {
                if ($entity instanceof User) {
                    return $violationList;
                }

                return $emptyViolationList;
            });

        $this->managerMock->expects($this->never())->method('persist');
        $this->managerMock->expects($this->never())->method('flush');

        $this->commandTester->setInputs([
            'jeff@example.com',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User name: User name is invalid', $output);
        $this->assertStringContainsString('There were some errors. Please start over again.', $output);
    }

    public function testExecuteMultipleValidationFailures(): void
    {
        $domainViolationList = new ConstraintViolationList();
        $domainViolationList->add(new ConstraintViolation('Domain name is invalid', null, [], null, 'name', 'example.com'));
        $domainViolationList->add(new ConstraintViolation('Domain name is too short', null, [], null, 'name', 'example.com'));

        $emptyViolationList = $this->createStub(ConstraintViolationListInterface::class);
        $emptyViolationList->method('count')->willReturn(0);

        $this->validatorMock
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->willReturnCallback(static function ($entity) use ($domainViolationList, $emptyViolationList) {
                if ($entity instanceof Domain) {
                    return $domainViolationList;
                }

                return $emptyViolationList;
            });

        $this->managerMock->expects($this->never())->method('persist');
        $this->managerMock->expects($this->never())->method('flush');

        $this->commandTester->setInputs([
            'jeff@example.com',
            '123456789',
            '123456789',
        ]);
        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Domain name: Domain name is invalid', $output);
        $this->assertStringContainsString('Domain name: Domain name is too short', $output);
    }

    public function testExecuteWithDifferentEmailFormat(): void
    {
        $violationList = $this->createStub(ConstraintViolationListInterface::class);
        $violationList->method('count')->willReturn(0);

        $this->validatorMock
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->willReturn($violationList);

        $this->managerMock->expects($this->once())->method('flush');
        $matcher = $this->exactly(2);
        $this->managerMock
            ->expects($matcher)
            ->method('persist')->willReturnCallback(function (...$parameters) use ($matcher) {
                if (1 === $matcher->numberOfInvocations()) {
                    $callback = function (Domain $domain) {
                        $this->assertEquals('test-domain.org', $domain->getName());

                        return true;
                    };
                    $this->assertTrue($callback($parameters[0]));
                }
                if (2 === $matcher->numberOfInvocations()) {
                    $callback = function (User $user) {
                        $this->assertEquals('admin', $user->getName());
                        $this->assertEquals('test-domain.org', $user->getDomain()->getName());

                        return true;
                    };
                    $this->assertTrue($callback($parameters[0]));
                }
            });

        $this->commandTester->setInputs([
            'admin@test-domain.org',
            'securepassword123',
            'securepassword123',
        ]);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\InitSetupCommand;
use App\Entity\Domain;
use App\Entity\User;
use App\Service\PasswordService;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InitSetupCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject $managerRegistryMock;

    private MockObject $managerMock;

    private MockObject $validatorMock;

    private MockObject $passwordService;

    protected function setUp(): void
    {
        $this->managerRegistryMock = $this->createMock(ManagerRegistry::class);
        $this->managerMock = $this->createMock(ObjectManager::class);
        $this->managerRegistryMock->method('getManager')->willReturn($this->managerMock);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);
        $this->passwordService = $this->createMock(PasswordService::class);

        $application = new Application();
        $application->add(new InitSetupCommand(null, $this->validatorMock, $this->managerRegistryMock, $this->passwordService));

        $this->commandTester = new CommandTester($application->find('init:setup'));
    }

    public function testExecute(): void
    {
        $violationList = $this->createMock(ConstraintViolationListInterface::class);

        $this->validatorMock
            ->method('validate')
            ->willReturn($violationList);

        $this->passwordService->expects($this->once())->method('processUserPassword');

        $this->managerMock->expects($this->once())->method('flush');
        $this->managerMock
            ->expects($this->exactly(2))
            ->method('persist')
            ->withConsecutive(
                $this->callback(
                    function (Domain $domain) {
                        $this->assertEquals('example.com', $domain->getName());
                    }
                ),
                $this->callback(
                    function (User $user) {
                        $this->assertEquals('jeff', $user->getName());
                        $this->assertEquals('123456789', $user->getPlainPassword());
                        $this->assertEquals('example.com', $user->getDomain()->getName());

                        return true;
                    }
                )
            );

        $this->commandTester->setInputs([
           'jEff@eXample.com',
           '123456789',
           '123456789',
        ]);
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}

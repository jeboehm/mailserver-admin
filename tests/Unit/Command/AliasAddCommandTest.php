<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Unit\Command;

use App\Command\AliasAddCommand;
use App\Entity\Alias;
use App\Entity\Domain;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AliasAddCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject $managerRegistryMock;

    private MockObject $managerMock;

    private MockObject $validatorMock;

    protected function setUp(): void
    {
        $this->managerRegistryMock = $this->createMock(ManagerRegistry::class);
        $this->managerMock = $this->createMock(ObjectManager::class);
        $this->managerRegistryMock->method('getManager')->willReturn($this->managerMock);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);

        $application = new Application();
        $application->add(new AliasAddCommand($this->managerRegistryMock, $this->validatorMock));

        $this->commandTester = new CommandTester($application->find('alias:add'));
    }

    public function testInvalidEmailFrom(): void
    {
        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'yolo', 'to' => 'jeff@example.com']);

        $this->assertEquals('yolo is not a valid email address.', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testInvalidEmailTo(): void
    {
        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'jeff@example.com', 'to' => 'yolo']);

        $this->assertEquals('yolo is not a valid email address.', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testDomainNotFound(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'jeff@example.com', 'to' => 'admin@example.com']);

        $this->assertEquals(
            'Domain example.com has to be created before.',
            trim($this->commandTester->getDisplay(true))
        );
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testValidationFail(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(new Domain());

        $violationList = new ConstraintViolationList();
        $violationList->add(new ConstraintViolation('Test', null, [], null, 'name', 1));

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->validatorMock
            ->method('validate')
            ->willReturn($violationList);

        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'jeff@example.com', 'to' => 'admin@example.com']);

        $this->assertEquals('name: Test', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecute(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(new Domain());

        $violationList = $this->createMock(ConstraintViolationListInterface::class);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->validatorMock
            ->method('validate')
            ->willReturn($violationList);

        $this->managerMock->expects($this->once())->method('flush');
        $this->managerMock
            ->expects($this->once())
            ->method('persist')
            ->with(
                $this->callback(
                    function (Alias $alias) {
                        $this->assertEquals('jeff', $alias->getName());
                        $this->assertEquals('admin@example.com', $alias->getDestination());

                        return true;
                    }
                )
            );

        $this->commandTester->execute(['from' => 'jeff@example.com', 'to' => 'admin@example.com']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}

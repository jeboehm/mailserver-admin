<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\UserAddCommand;
use App\Entity\Domain;
use App\Entity\User;
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

class UserAddCommandTest extends TestCase
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
        $application->add(new UserAddCommand($this->managerRegistryMock, $this->validatorMock));

        $this->commandTester = new CommandTester($application->find('user:add'));
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

        $this->commandTester->execute(['name' => 'jeff', 'domain' => 'example.com']);

        $this->assertEquals(
            'Domain example.com was not found.',
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

        $this->commandTester->execute(['name' => 'JEFF', 'domain' => 'example.com', '--password' => 'jeff']);

        $this->assertEquals('name: Test', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecute(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $domain = new Domain();
        $repository->method('findOneBy')->willReturn($domain);

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
                    function (User $user) use ($domain) {
                        $this->assertSame($domain, $user->getDomain());
                        $this->assertEquals('jeff', $user->getName());
                        $this->assertEquals('jeff', $user->getPlainPassword());

                        return true;
                    }
                )
            );

        $this->commandTester->execute(['name' => 'JEFF', 'domain' => 'example.com', '--password' => 'jeff']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}

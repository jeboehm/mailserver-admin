<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\AliasAddCommand;
use App\Entity\Alias;
use App\Entity\Domain;
use App\Repository\DomainRepository;
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

class AliasAddCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject&EntityManagerInterface $managerMock;

    private MockObject&DomainRepository $domainRepositoryMock;

    private MockObject&ValidatorInterface $validatorMock;

    private MockObject&ConnectionCheckService $connectionCheckServiceMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(EntityManagerInterface::class);
        $this->domainRepositoryMock = $this->createMock(DomainRepository::class);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);

        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => null, 'redis' => null]);

        $application = new Application();
        $application->add(
            new AliasAddCommand($this->managerMock, $this->domainRepositoryMock, $this->validatorMock, $this->connectionCheckServiceMock)
        );

        $this->commandTester = new CommandTester($application->find('alias:add'));
    }

    public function testInvalidEmailFrom(): void
    {
        $this->domainRepositoryMock->expects($this->never())->method('findOneBy');
        $this->validatorMock->expects($this->never())->method('validate');
        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'yolo', 'to' => 'jeff@example.com']);

        $this->assertEquals('yolo is not a valid email address.', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testInvalidEmailTo(): void
    {
        $this->domainRepositoryMock->expects($this->never())->method('findOneBy');
        $this->validatorMock->expects($this->never())->method('validate');
        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'jeff@example.com', 'to' => 'yolo']);

        $this->assertEquals('yolo is not a valid email address.', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testDomainNotFound(): void
    {
        $this->validatorMock->expects($this->never())->method('validate');
        $this->domainRepositoryMock->expects($this->once())->method('findOneBy')->willReturn(null);
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
        $this->domainRepositoryMock->expects($this->once())->method('findOneBy')->willReturn(new Domain());

        $violationList = new ConstraintViolationList();
        $violationList->add(new ConstraintViolation('Test', null, [], null, 'name', 1));

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn($violationList);

        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['from' => 'jeff@example.com', 'to' => 'admin@example.com']);

        $this->assertEquals('name: Test', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecute(): void
    {
        $this->domainRepositoryMock->expects($this->once())->method('findOneBy')->willReturn(new Domain());
        $violationList = $this->createStub(ConstraintViolationListInterface::class);

        $this->validatorMock
            ->expects($this->once())
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

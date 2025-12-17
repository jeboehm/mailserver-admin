<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\UserAddCommand;
use App\Entity\Domain;
use App\Entity\User;
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

class UserAddCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject&EntityManagerInterface $managerMock;

    private MockObject&DomainRepository $domainRepository;

    private MockObject&ValidatorInterface $validatorMock;

    private MockObject&ConnectionCheckService $connectionCheckServiceMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(EntityManagerInterface::class);
        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);

        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => null, 'redis' => null]);

        $application = new Application();
        $application->add(new UserAddCommand($this->managerMock, $this->domainRepository, $this->validatorMock, $this->connectionCheckServiceMock));

        $this->commandTester = new CommandTester($application->find('user:add'));
    }

    public function testDomainNotFound(): void
    {
        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn(null);
        $this->managerMock->expects($this->never())->method('persist');
        $this->validatorMock->expects($this->never())->method('validate');

        $this->commandTester->execute(['name' => 'jeff', 'domain' => 'example.com']);

        $this->assertEquals(
            'Domain example.com was not found.',
            trim($this->commandTester->getDisplay(true))
        );
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testValidationFail(): void
    {
        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn(new Domain());

        $violationList = new ConstraintViolationList();
        $violationList->add(new ConstraintViolation('Test', null, [], null, 'name', 1));

        $this->validatorMock
            ->expects($this->once())
            ->method('validate')
            ->willReturn($violationList);

        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['name' => 'JEFF', 'domain' => 'example.com', '--password' => 'jeff']);

        $this->assertEquals('name: Test', trim($this->commandTester->getDisplay(true)));
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecute(): void
    {
        $domain = new Domain();
        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn($domain);

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

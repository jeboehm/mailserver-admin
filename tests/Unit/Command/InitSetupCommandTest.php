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

    protected function setUp(): void
    {
        $this->managerRegistryMock = $this->createMock(ManagerRegistry::class);
        $this->managerMock = $this->createMock(ObjectManager::class);
        $this->managerRegistryMock->method('getManager')->willReturn($this->managerMock);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);

        $application = new Application();
        $application->add(new InitSetupCommand($this->validatorMock, $this->managerRegistryMock));

        $this->commandTester = new CommandTester($application->find('init:setup'));
    }

    public function testExecute(): void
    {
        $violationList = $this->createMock(ConstraintViolationListInterface::class);

        $this->validatorMock
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
                        $this->assertEquals('example.com', $user->getDomain()->getName());

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
    }
}

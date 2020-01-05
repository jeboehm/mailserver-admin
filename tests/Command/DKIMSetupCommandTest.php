<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\DKIMSetupCommand;
use App\Entity\Domain;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DKIMSetupCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject $managerRegistryMock;

    private MockObject $managerMock;

    protected function setUp(): void
    {
        $this->managerRegistryMock = $this->createMock(ManagerRegistry::class);
        $this->managerMock = $this->createMock(ObjectManager::class);
        $this->managerRegistryMock->method('getManager')->willReturn($this->managerMock);

        $application = new Application();
        $application->add(
            new DKIMSetupCommand(null, $this->managerRegistryMock, new KeyGenerationService(), new FormatterService())
        );

        $this->commandTester = new CommandTester($application->find('dkim:setup'));
    }

    public function testDomainNotFound(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->managerMock->expects($this->never())->method('flush');

        $this->commandTester->execute(['domain' => 'example.com']);

        $this->assertEquals(
            'Domain "example.com" was not found.',
            trim($this->commandTester->getDisplay(true))
        );
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testKeyIsNotRegenerated(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($domain);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->commandTester->execute(['domain' => 'example.com']);

        $privateKey = $domain->getDkimPrivateKey();

        $this->commandTester->setInputs(['Y']);
        $this->commandTester->execute(['domain' => 'example.com']);

        $this->assertEquals($privateKey, $domain->getDkimPrivateKey());
    }

    public function testKeyIsRegenerated(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($domain);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->commandTester->execute(['domain' => 'example.com']);

        $privateKey = $domain->getDkimPrivateKey();

        $this->commandTester->setInputs(['Y']);
        $this->commandTester->execute(['domain' => 'example.com', '--regenerate' => true]);

        $this->assertNotEquals($privateKey, $domain->getDkimPrivateKey());
    }

    public function testDkimIsEnabled(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($domain);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->managerMock->expects($this->once())->method('flush');

        $this->commandTester->execute(['domain' => 'example.com', '--enable' => true]);

        $this->assertTrue($domain->getDkimEnabled());
        $this->assertNotEmpty($domain->getDkimPrivateKey());
        $this->assertNotEmpty($domain->getDkimSelector());
    }

    public function testRegenerationQuestionNo(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn($domain);

        $this->managerRegistryMock
            ->method('getRepository')
            ->with(Domain::class)
            ->willReturn($repository);

        $this->commandTester->setInputs(['N']);
        $this->commandTester->execute(['domain' => 'example.com', '--regenerate' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }
}

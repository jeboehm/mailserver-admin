<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\DKIMSetupCommand;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\ConnectionCheckService;
use App\Service\DKIM\Config\Manager;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DKIMSetupCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject&EntityManagerInterface $managerMock;

    private MockObject&DomainRepository $domainRepository;

    private MockObject&Manager $dkimManagerMock;

    private MockObject&ConnectionCheckService $connectionCheckServiceMock;

    private KeyGenerationService $keyGenerationService;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(EntityManagerInterface::class);
        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->dkimManagerMock = $this->createMock(Manager::class);
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);
        $this->keyGenerationService = new KeyGenerationService();

        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => null, 'redis' => null]);

        $application = new Application();
        $application->add(
            new DKIMSetupCommand(
                $this->managerMock,
                $this->domainRepository,
                new KeyGenerationService(),
                new FormatterService(),
                $this->dkimManagerMock,
                $this->connectionCheckServiceMock
            )
        );

        $this->commandTester = new CommandTester($application->find('dkim:setup'));
    }

    public function testDomainNotFound(): void
    {
        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn(null);
        $this->managerMock->expects($this->never())->method('flush');
        $this->dkimManagerMock->expects($this->never())->method('refresh');

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
        $privateKey = $this->keyGenerationService->createKeyPair()->getPrivate();
        $domain->setDkimPrivateKey($privateKey);

        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn($domain);
        $this->managerMock->expects($this->once())->method('flush');
        $this->dkimManagerMock->expects($this->once())->method('refresh');

        $this->commandTester->execute(['domain' => 'example.com']);

        $this->assertEquals($privateKey, $domain->getDkimPrivateKey());
    }

    public function testKeyIsRegenerated(): void
    {
        $privateKey = $this->keyGenerationService->createKeyPair()->getPrivate();
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimPrivateKey($privateKey);

        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn($domain);
        $this->managerMock->expects($this->once())->method('flush');
        $this->dkimManagerMock->expects($this->once())->method('refresh');

        $this->commandTester->setInputs(['Y']);
        $this->commandTester->execute(['domain' => 'example.com', '--regenerate' => true]);

        $this->assertNotEquals($privateKey, $domain->getDkimPrivateKey());
    }

    public function testDkimIsEnabled(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn($domain);
        $this->managerMock->expects($this->once())->method('flush');
        $this->dkimManagerMock->expects($this->once())->method('refresh');

        $this->commandTester->execute(['domain' => 'example.com', '--enable' => true]);

        $this->assertTrue($domain->getDkimEnabled());
        $this->assertNotEmpty($domain->getDkimPrivateKey());
        $this->assertNotEmpty($domain->getDkimSelector());
    }

    public function testRegenerationQuestionNo(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $this->domainRepository->expects($this->once())->method('findOneBy')->willReturn($domain);
        $this->managerMock->expects($this->never())->method('flush');
        $this->dkimManagerMock->expects($this->never())->method('refresh');

        $this->commandTester->setInputs(['N']);
        $this->commandTester->execute(['domain' => 'example.com', '--regenerate' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }
}

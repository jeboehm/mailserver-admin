<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\DKIMDisableCommand;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\ConnectionCheckService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DKIMDisableCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject&EntityManagerInterface $managerMock;

    private MockObject&ConnectionCheckService $connectionCheckServiceMock;
    private MockObject&DomainRepository $domainRepositoryMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(EntityManagerInterface::class);
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);
        $this->domainRepositoryMock = $this->createMock(DomainRepository::class);

        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => null, 'redis' => null]);

        $application = new Application();
        $application->addCommand(new DKIMDisableCommand($this->managerMock, $this->domainRepositoryMock, $this->connectionCheckServiceMock));

        $this->commandTester = new CommandTester($application->find('dkim:disable'));
    }

    public function testDomainNotFound(): void
    {
        $this->domainRepositoryMock->expects($this->once())->method('findOneBy')->willReturn(null);

        $this->managerMock->expects($this->never())->method('persist');

        $this->commandTester->execute(['domain' => 'example.com']);

        $this->assertEquals(
            'Domain "example.com" was not found.',
            trim($this->commandTester->getDisplay(true))
        );
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testAbort(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimPrivateKey('xy');
        $domain->setDkimSelector('xy');
        $domain->setDkimEnabled(true);

        $this->domainRepositoryMock->expects($this->once())->method('findOneBy')->willReturn($domain);
        $this->managerMock->expects($this->never())->method('flush');

        $this->commandTester->setInputs(['no']);
        $this->commandTester->execute(['domain' => 'example.com']);

        $this->assertEquals(
            'Do you want to disable DKIM for domain "example.com"?Aborting.',
            trim($this->commandTester->getDisplay(true))
        );
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecute(): void
    {
        $domain = new Domain();
        $domain->setDkimPrivateKey('xy');
        $domain->setDkimSelector('xy');
        $domain->setDkimEnabled(true);

        $this->domainRepositoryMock->expects($this->once())->method('findOneBy')->willReturn($domain);
        $this->managerMock->expects($this->once())->method('flush');

        $this->commandTester->setInputs(['Y']);
        $this->commandTester->execute(['domain' => 'example.com']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertFalse($domain->getDkimEnabled());
    }
}

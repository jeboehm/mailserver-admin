<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DKIM;

use App\Entity\Domain;
use App\Service\DKIM\DKIMStatus;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use App\Service\DKIM\RuntimeDataLoader;
use PHPUnit\Framework\TestCase;

class RuntimeDataLoaderTest extends TestCase
{
    public function testLoadingADomainDoesNoDnsLookup(): void
    {
        $statusService = $this->createMock(DKIMStatusService::class);
        $statusService->expects($this->never())->method('getStatus');

        $domain = new Domain();
        $domain->setName('example.com');

        $this->loader($statusService)->postLoad($domain);

        self::assertSame('example.com', $domain->getName());
    }

    public function testStatusIsLookedUpOnFirstUseAndOnlyOnce(): void
    {
        $status = new DKIMStatus(true, true, true, 'v=DKIM1; k=rsa; p=abc');
        $statusService = $this->createMock(DKIMStatusService::class);
        $statusService->expects($this->once())->method('getStatus')->willReturn($status);

        $domain = new Domain();
        $this->loader($statusService)->postLoad($domain);

        $domain->getCurrentDnsRecord();
        self::assertSame($status, $domain->getDkimStatus());
    }

    public function testDnsRecordsAreFilledInOnFirstUse(): void
    {
        $keyGeneration = new KeyGenerationService();
        $privateKey = $keyGeneration->createKeyPair()->getPrivate();

        $statusService = $this->createStub(DKIMStatusService::class);
        $statusService->method('getStatus')->willReturn(new DKIMStatus(true, true, true, 'the current record'));

        $domain = new Domain();
        $domain->setDkimPrivateKey($privateKey);
        $this->loader($statusService, $keyGeneration)->postLoad($domain);

        self::assertSame('the current record', $domain->getCurrentDnsRecord());
        self::assertStringStartsWith('v=DKIM1', (string) $domain->getExpectedDnsRecord());
    }

    public function testRecordsStayEmptyWithoutAKey(): void
    {
        $statusService = $this->createStub(DKIMStatusService::class);
        $statusService->method('getStatus')->willReturn(new DKIMStatus(false, false, false, ''));

        $domain = new Domain();
        $this->loader($statusService)->postLoad($domain);

        self::assertNull($domain->getExpectedDnsRecord());
        self::assertNull($domain->getCurrentDnsRecord());
    }

    public function testDomainThatWasNeverLoadedHasNoStatus(): void
    {
        self::assertNull(new Domain()->getDkimStatus());
    }

    public function testReloadingLooksTheStatusUpAgain(): void
    {
        $first = new DKIMStatus(true, false, false, '');
        $second = new DKIMStatus(true, true, true, 'record');
        $statusService = $this->createStub(DKIMStatusService::class);
        $statusService->method('getStatus')->willReturnOnConsecutiveCalls($first, $second);

        $domain = new Domain();
        $loader = $this->loader($statusService);

        $loader->postLoad($domain);
        self::assertSame($first, $domain->getDkimStatus());

        $loader->postLoad($domain);
        self::assertSame($second, $domain->getDkimStatus());
    }

    private function loader(DKIMStatusService $statusService, KeyGenerationService $keyGeneration = new KeyGenerationService()): RuntimeDataLoader
    {
        return new RuntimeDataLoader(new FormatterService(), $statusService, $keyGeneration);
    }
}

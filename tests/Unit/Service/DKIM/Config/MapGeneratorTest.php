<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DKIM\Config;

use App\Entity\Domain;
use App\Service\DKIM\Config\MapGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

class MapGeneratorTest extends TestCase
{
    private MockObject|ClientInterface $redis;
    private MapGenerator $mapGenerator;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(ClientInterface::class);
        $this->mapGenerator = new MapGenerator($this->redis);
    }

    public function testGenerateWithEmptyDomains(): void
    {
        $this->redis
            ->expects($this->never())
            ->method('__call');

        $this->mapGenerator->generate();
    }

    public function testGenerateWithDomainDkimDisabled(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(false);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('private-key-content');

        $this->redis
            ->expects($this->never())
            ->method('__call');

        $this->mapGenerator->generate($domain);
    }

    public function testGenerateWithDomainEmptyPrivateKey(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('');

        $this->redis
            ->expects($this->never())
            ->method('__call');

        $this->mapGenerator->generate($domain);
    }

    public function testGenerateWithDomainEmptySelector(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('');
        $domain->setDkimPrivateKey('private-key-content');

        $this->redis
            ->expects($this->never())
            ->method('__call');

        $this->mapGenerator->generate($domain);
    }

    public function testGenerateWithSingleValidDomain(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('private-key-content');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('hmset', ['dkim_keys', ['dkim.example.com' => 'private-key-content']]);

        $this->mapGenerator->generate($domain);
    }

    public function testGenerateWithMultipleValidDomains(): void
    {
        $domain1 = new Domain();
        $domain1->setName('example.com');
        $domain1->setDkimEnabled(true);
        $domain1->setDkimSelector('dkim');
        $domain1->setDkimPrivateKey('private-key-1');

        $domain2 = new Domain();
        $domain2->setName('test.org');
        $domain2->setDkimEnabled(true);
        $domain2->setDkimSelector('mail');
        $domain2->setDkimPrivateKey('private-key-2');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('hmset', ['dkim_keys', [
                'dkim.example.com' => 'private-key-1',
                'mail.test.org' => 'private-key-2',
            ]]);

        $this->mapGenerator->generate($domain1, $domain2);
    }

    public function testGenerateWithMixedValidAndInvalidDomains(): void
    {
        $validDomain = new Domain();
        $validDomain->setName('example.com');
        $validDomain->setDkimEnabled(true);
        $validDomain->setDkimSelector('dkim');
        $validDomain->setDkimPrivateKey('private-key-content');

        $disabledDomain = new Domain();
        $disabledDomain->setName('disabled.com');
        $disabledDomain->setDkimEnabled(false);
        $disabledDomain->setDkimSelector('dkim');
        $disabledDomain->setDkimPrivateKey('private-key-content');

        $emptyKeyDomain = new Domain();
        $emptyKeyDomain->setName('emptykey.com');
        $emptyKeyDomain->setDkimEnabled(true);
        $emptyKeyDomain->setDkimSelector('dkim');
        $emptyKeyDomain->setDkimPrivateKey('');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('hmset', ['dkim_keys', ['dkim.example.com' => 'private-key-content']]);

        $this->mapGenerator->generate($validDomain, $disabledDomain, $emptyKeyDomain);
    }

    public function testGenerateWithAllInvalidDomains(): void
    {
        $disabledDomain = new Domain();
        $disabledDomain->setName('disabled.com');
        $disabledDomain->setDkimEnabled(false);
        $disabledDomain->setDkimSelector('dkim');
        $disabledDomain->setDkimPrivateKey('private-key-content');

        $emptyKeyDomain = new Domain();
        $emptyKeyDomain->setName('emptykey.com');
        $emptyKeyDomain->setDkimEnabled(true);
        $emptyKeyDomain->setDkimSelector('dkim');
        $emptyKeyDomain->setDkimPrivateKey('');

        $emptySelectorDomain = new Domain();
        $emptySelectorDomain->setName('emptyselector.com');
        $emptySelectorDomain->setDkimEnabled(true);
        $emptySelectorDomain->setDkimSelector('');
        $emptySelectorDomain->setDkimPrivateKey('private-key-content');

        $this->redis
            ->expects($this->never())
            ->method('__call');

        $this->mapGenerator->generate($disabledDomain, $emptyKeyDomain, $emptySelectorDomain);
    }

    public function testGenerateWithCustomSelector(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('mail');
        $domain->setDkimPrivateKey('private-key-content');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('hmset', ['dkim_keys', ['mail.example.com' => 'private-key-content']]);

        $this->mapGenerator->generate($domain);
    }
}

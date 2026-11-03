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
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

class MapGeneratorTest extends TestCase
{
    /** @var array<string, string> what the hash holds in Redis */
    private array $stored = [];
    private MapGenerator $mapGenerator;

    protected function setUp(): void
    {
        $this->stored = [];

        $redis = $this->createStub(ClientInterface::class);
        $redis->method('__call')->willReturnCallback(function (string $command, array $arguments): mixed {
            self::assertSame('dkim_keys', $arguments[0]);

            return match ($command) {
                'hmset' => $this->stored = [...$this->stored, ...$arguments[1]],
                'hkeys' => array_keys($this->stored),
                'hdel' => $this->stored = array_diff_key($this->stored, array_flip($arguments[1])),
                default => self::fail(\sprintf('Unexpected Redis command "%s".', $command)),
            };
        });

        $this->mapGenerator = new MapGenerator($redis);
    }

    public function testGenerateWithEmptyDomains(): void
    {
        $this->mapGenerator->generate();

        self::assertSame([], $this->stored);
    }

    public function testGenerateWithDomainDkimDisabled(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(false);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('private-key-content');

        $this->mapGenerator->generate($domain);

        self::assertSame([], $this->stored);
    }

    public function testGenerateWithDomainEmptyPrivateKey(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('');

        $this->mapGenerator->generate($domain);

        self::assertSame([], $this->stored);
    }

    public function testGenerateWithDomainEmptySelector(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('');
        $domain->setDkimPrivateKey('private-key-content');

        $this->mapGenerator->generate($domain);

        self::assertSame([], $this->stored);
    }

    public function testGenerateWithSingleValidDomain(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('private-key-content');

        $this->mapGenerator->generate($domain);

        self::assertSame(['dkim.example.com' => 'private-key-content'], $this->stored);
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

        $this->mapGenerator->generate($domain1, $domain2);

        self::assertSame([
            'dkim.example.com' => 'private-key-1',
            'mail.test.org' => 'private-key-2',
        ], $this->stored);
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

        $this->mapGenerator->generate($validDomain, $disabledDomain, $emptyKeyDomain);

        self::assertSame(['dkim.example.com' => 'private-key-content'], $this->stored);
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

        $this->mapGenerator->generate($disabledDomain, $emptyKeyDomain, $emptySelectorDomain);

        self::assertSame([], $this->stored);
    }

    public function testGenerateWithCustomSelector(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('mail');
        $domain->setDkimPrivateKey('private-key-content');

        $this->mapGenerator->generate($domain);

        self::assertSame(['mail.example.com' => 'private-key-content'], $this->stored);
    }

    public function testDisabledDomainIsRemoved(): void
    {
        $this->stored = ['dkim.example.com' => 'private-key-content'];

        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(false);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('private-key-content');

        $this->mapGenerator->generate($domain);

        self::assertSame([], $this->stored);
    }

    public function testRemovingOneDomainKeepsTheOthers(): void
    {
        $this->stored = ['dkim.example.com' => 'old', 'dkim.gone.example' => 'old'];

        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey('new');

        $this->mapGenerator->generate($domain);

        self::assertSame(['dkim.example.com' => 'new'], $this->stored);
    }
}

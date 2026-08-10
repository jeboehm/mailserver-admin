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
use App\Exception\DKIM\DomainKeyNotFoundException;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\DomainKey;
use App\Service\DKIM\DomainKeyReaderService;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use App\Service\DKIM\RecordParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DKIMStatusServiceTest extends TestCase
{
    private const string PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5SuczaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxbh/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB';

    private const string OTHER_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAquTL5cOnOaQ5WBU//UZ20di90Sdy39jZq2exSH0F7K1czItBL8nU0zalto5ZLk1zZKUNYLD5ys1CkoMFzvKsudTlIDnkHhXZqZCmGsExFbTAacUvmeOpGZQaESo9dk+0opwJKUUxn6nJKlWhSnPGQqMQsFey4iJ0Gyc/h+SpNzjjiLBnoqmKWu0G7tpqOZ1wjOxAlBij5ZSnEHchsmeodYMi+YTuHCDMKH9wruTdmUMSeakPpP42HoZPoxK+rRUBJ98WmlF4cQ2T0OhqLe6hACEANUxe2Clt0LK+iwI9TVF/v3r8Z3K9OYhKjGqSmHIO3j0WO0vHnGs+/aFnqRpKJQIDAQAB';

    private const string EXPECTED_RECORD = 'v=DKIM1; h=sha256; t=s; p=' . self::PUBLIC_KEY;

    public function testDKIMDisabled(): void
    {
        $status = $this->createService()->getStatus($this->createDomain(enabled: false, privateKey: ''));

        $this->assertFalse($status->isDkimEnabled());
        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
        $this->assertEmpty($status->getExpectedRecord());
    }

    public function testDKIMEnabledButNoKey(): void
    {
        $status = $this->createService()->getStatus($this->createDomain(enabled: true, privateKey: ''));

        $this->assertTrue($status->isDkimEnabled());
        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
    }

    public function testRecordNotFound(): void
    {
        $status = $this->createService(new DomainKeyNotFoundException())->getStatus($this->createDomain());

        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
        $this->assertSame(self::EXPECTED_RECORD, $status->getExpectedRecord());
    }

    /**
     * The way a record is spelled differs per DNS provider. As long as the published public key is
     * the one belonging to the domain, the record is valid.
     */
    #[DataProvider('dataProviderForTestValidRecords')]
    public function testValidRecords(string $publishedRecord): void
    {
        $status = $this->createService($publishedRecord)->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertTrue($status->isDkimRecordValid(), implode(' ', $status->getIssues()));
        $this->assertSame([], $status->getIssues());
        $this->assertSame($publishedRecord, $status->getCurrentRecord());
        $this->assertSame(self::EXPECTED_RECORD, $status->getExpectedRecord());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dataProviderForTestValidRecords(): array
    {
        return [
            'exactly as generated' => [self::EXPECTED_RECORD],
            'without spaces after the separators' => ['v=DKIM1;h=sha256;t=s;p=' . self::PUBLIC_KEY],
            'with a trailing semicolon' => [self::EXPECTED_RECORD . ';'],
            'with an additional k tag' => ['v=DKIM1; k=rsa; h=sha256; t=s; p=' . self::PUBLIC_KEY],
            'with the tags in a different order' => ['p=' . self::PUBLIC_KEY . '; v=DKIM1; t=s; h=sha256'],
            'without the optional t tag' => ['v=DKIM1; h=sha256; p=' . self::PUBLIC_KEY],
            'without the optional v tag' => ['h=sha256; t=s; p=' . self::PUBLIC_KEY],
            'with the key wrapped over several lines' => [
                'v=DKIM1; h=sha256; t=s; p=' . substr(self::PUBLIC_KEY, 0, 120) . "\n " . substr(self::PUBLIC_KEY, 120),
            ],
        ];
    }

    /**
     * Pasting the record including the escaping of a BIND zone file is the most common mistake:
     * the key itself still matches, so the record has to be rejected on the backslashes.
     */
    public function testRecordPublishedWithZoneFileEscaping(): void
    {
        $published = 'v=DKIM1\; h=sha256\; t=s\; p=' . self::PUBLIC_KEY;

        $status = $this->createService($published)->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertStringContainsString('backslashes', $status->getIssues()[0]);
        $this->assertSame($published, $status->getCurrentRecord());
    }

    public function testRecordWithAnotherKey(): void
    {
        $status = $this->createService('v=DKIM1; h=sha256; t=s; p=' . self::OTHER_PUBLIC_KEY)
            ->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertStringContainsString('different key pair', $status->getIssues()[0]);
    }

    public function testRevokedKey(): void
    {
        $status = $this->createService('v=DKIM1; p=')->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertStringContainsString('revokes', $status->getIssues()[0]);
    }

    public function testRecordWithoutPublicKey(): void
    {
        $status = $this->createService('v=DKIM1; h=sha256')->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertStringContainsString('does not contain a public key', $status->getIssues()[0]);
    }

    public function testRecordWithCorruptedKey(): void
    {
        $status = $this->createService('v=DKIM1; h=sha256; t=s; p=' . self::PUBLIC_KEY . '***')
            ->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertStringContainsString('not valid base64', $status->getIssues()[0]);
    }

    public function testRecordWithWrongVersion(): void
    {
        $status = $this->createService('v=DKIM2; h=sha256; t=s; p=' . self::PUBLIC_KEY)
            ->getStatus($this->createDomain());

        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertStringContainsString('v=DKIM2', $status->getIssues()[0]);
    }

    private function createDomain(bool $enabled = true, string $privateKey = 'private-key'): Domain
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled($enabled);
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey($privateKey);

        return $domain;
    }

    private function createService(string|DomainKeyNotFoundException|null $published = null): DKIMStatusService
    {
        $parser = new RecordParser();
        $reader = $this->createStub(DomainKeyReaderService::class);

        if ($published instanceof DomainKeyNotFoundException) {
            $reader->method('getDomainKey')->willThrowException($published);
        } elseif (null !== $published) {
            $reader->method('getDomainKey')->willReturn(new DomainKey($published, $parser->parse($published)));
        }

        $keyGeneration = $this->createStub(KeyGenerationService::class);
        $keyGeneration->method('extractPublicKey')->willReturn(
            \sprintf("-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n", self::PUBLIC_KEY)
        );

        return new DKIMStatusService($reader, new FormatterService(), $keyGeneration, $parser);
    }
}

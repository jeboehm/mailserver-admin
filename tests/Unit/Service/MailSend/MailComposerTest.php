<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MailSend;

use App\Exception\MailSend\InvalidMailException;
use App\Service\MailSend\MailComposer;
use App\Service\MailSend\OutgoingMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailComposerTest extends TestCase
{
    private const string SENDER = 'alice@example.com';

    public function testTheSenderIsTheGivenAddress(): void
    {
        $mail = $this->compose(['bob@example.org']);

        self::assertSame(['alice@example.com'], array_map(static fn ($address): string => $address->getAddress(), $mail->email->getFrom()));
    }

    public function testTheMessageIdFollowsTheDomainOfAnotherSender(): void
    {
        $mail = new MailComposer(20, 1048576)->compose('info@example.net', ['bob@example.org'], [], [], 'Hello', 'Body');

        self::assertSame('info@example.net', $mail->email->getFrom()[0]->getAddress());
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.net>$/', $mail->messageId);
    }

    public function testRecipientsAreCountedOnceAcrossToCcAndBcc(): void
    {
        $mail = $this->compose(['a@example.org', 'b@example.org'], ['a@example.org'], ['b@example.org', 'c@example.org']);

        self::assertSame(['a@example.org', 'b@example.org', 'c@example.org'], $mail->recipients);
    }

    public function testTheLimitOnRecipientsCountsDistinctAddresses(): void
    {
        $mail = new MailComposer(2, 1048576)->compose(self::SENDER, ['a@example.org'], ['a@example.org'], ['b@example.org'], 'Hello', 'Body');

        self::assertCount(2, $mail->recipients);

        $this->expectException(InvalidMailException::class);

        new MailComposer(2, 1048576)->compose(self::SENDER, ['a@example.org'], ['b@example.org'], ['c@example.org'], 'Hello', 'Body');
    }

    public function testTheMessageIdIsMarkedWithTheDomainOfTheSender(): void
    {
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.com>$/', $this->compose(['bob@example.org'])->messageId);
    }

    public function testTheMailCarriesExactlyOneFixedXMailerHeader(): void
    {
        $mail = $this->compose(['bob@example.org']);

        self::assertCount(1, iterator_to_array($mail->email->getHeaders()->all('X-Mailer')));
        self::assertSame('mailserver-admin (https://github.com/jeboehm/mailserver-admin)', $mail->email->getHeaders()->getHeaderBody('X-Mailer'));
        self::assertSame(1, substr_count($mail->sentCopy(), "X-Mailer: mailserver-admin (https://github.com/jeboehm/mailserver-admin)\r\n"));
    }

    public function testTheXMailerValueDoesNotDependOnTheSender(): void
    {
        $first = $this->compose(['x@example.org']);
        $second = new MailComposer(20, 1048576)->compose('bob@example.net', ['x@example.org'], [], [], 'Other', 'Other body');

        self::assertSame(
            $first->email->getHeaders()->getHeaderBody('X-Mailer'),
            $second->email->getHeaders()->getHeaderBody('X-Mailer'),
        );
    }

    public function testTheSentCopyAloneShowsBlindCopies(): void
    {
        $mail = $this->compose(['bob@example.org'], [], ['hidden@example.org']);

        self::assertStringContainsString('hidden@example.org', $mail->sentCopy());
        self::assertSame(['hidden@example.org'], array_map(static fn ($address): string => $address->getAddress(), $mail->email->getBcc()));
    }

    public function testAMessageOverTheLimitIsRefusedByItsSizeOnTheWire(): void
    {
        $this->expectException(InvalidMailException::class);
        $this->expectExceptionMessage('larger than the limit of 1000 bytes');

        // Base64 or quoted-printable make non-ASCII text larger than its bytes.
        new MailComposer(20, 1000)->compose(self::SENDER, ['bob@example.org'], [], [], 'Hello', str_repeat('ü', 400));
    }

    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function invalidMails(): iterable
    {
        yield 'no recipient' => [[], 'Hello', 'Body'];
        yield 'a display name' => [['Bob <bob@example.org>'], 'Hello', 'Body'];
        yield 'two addresses in one' => [['a@example.org, b@example.org'], 'Hello', 'Body'];
        yield 'a line break in an address' => [["bob@example.org\r\nBcc: x@example.org"], 'Hello', 'Body'];
        yield 'non-ASCII before the @' => [['jörg@example.org'], 'Hello', 'Body'];
        yield 'an empty subject' => [['bob@example.org'], '  ', 'Body'];
        yield 'a header smuggled in through the subject' => [['bob@example.org'], "Hello\r\nBcc: x@example.org", 'Body'];
        yield 'an empty body' => [['bob@example.org'], 'Hello', " \n"];
    }

    /**
     * @param list<string> $to
     */
    #[DataProvider('invalidMails')]
    public function testRefuses(array $to, string $subject, string $body): void
    {
        $this->expectException(InvalidMailException::class);

        new MailComposer(20, 1048576)->compose(self::SENDER, $to, [], [], $subject, $body);
    }

    public function testANonAsciiDomainIsFine(): void
    {
        self::assertSame(['bob@müller.example'], $this->compose(['bob@müller.example'])->recipients);
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    private function compose(array $to, array $cc = [], array $bcc = []): OutgoingMail
    {
        return new MailComposer(20, 1048576)->compose(self::SENDER, $to, $cc, $bcc, 'Hello', 'Body');
    }
}

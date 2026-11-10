<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MailDraft;

use App\Exception\Mailbox\MailboxDeniedException;
use App\Exception\MailDraft\MailDraftFailedException;
use App\Exception\MailDraft\MailDraftRateLimitedException;
use App\Service\MailDraft\MailDrafter;
use App\Service\MailDraft\MailDraftRateLimiter;
use App\Service\MailSend\OutgoingMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\Email;
use Tests\Integration\Helper\FakeImapServer;
use Tests\Unit\Helper\Limiters;
use Tests\Unit\Service\Mailbox\ImapFixtureTrait;

class MailDrafterTest extends TestCase
{
    use ImapFixtureTrait;

    public function testSavesInTheMarkedMailboxWithTheDraftFlag(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\HasNoChildren \Drafts) "/" Entwürfe',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);

        $mailbox = $this->drafter($server)->save($this->mailUser(), 1, $this->mail());

        self::assertSame('Entwürfe', $mailbox);
        self::assertStringContainsString('(\Draft \Seen) {', $server->sent());
    }

    public function testPrefersDraftsThenTheFirstByName(): void
    {
        $both = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Drafts) "/" Entwürfe',
            '* LIST (\Drafts) "/" Drafts',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);
        self::assertSame('Drafts', $this->drafter($both)->save($this->mailUser(), 1, $this->mail()));

        $other = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Drafts) "/" Zzz',
            '* LIST (\Drafts) "/" Aaa',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);
        self::assertSame('Aaa', $this->drafter($other)->save($this->mailUser(), 1, $this->mail()));
    }

    public function testCreatesTheMailboxWhenTheServerAsksForIt(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST () "/" INBOX',
            'TAG2 OK Listed',
            'TAG3 NO [TRYCREATE] Mailbox does not exist',
            'TAG4 OK Created',
            '+ Ready for literal',
            'TAG5 OK Appended',
        ]);

        $mailbox = $this->drafter($server)->save($this->mailUser(), 1, $this->mail());

        self::assertSame('Drafts', $mailbox);
        self::assertStringContainsString('TAG4 CREATE "Drafts"', $server->sent());
    }

    public function testAFailedAppendIsAnExceptionAndLeavesTheMailOutOfTheLog(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Drafts) "/" Drafts',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 NO [OVERQUOTA] Quota exceeded',
        ]);

        try {
            $this->drafter($server)->save($this->mailUser(), 1, $this->mail());
            self::fail('A failed save must not look like a success.');
        } catch (MailDraftFailedException $e) {
            self::assertStringNotContainsString('Retry', $e->getMessage());
        }

        self::assertStringNotContainsString('sentinel body', $this->loggedImapMessages());
    }

    public function testATemporaryFailureSaysSo(): void
    {
        $server = new FakeImapServer(['TAG1 NO [LIMIT] Too many connections']);

        $this->expectException(MailDraftFailedException::class);
        $this->expectExceptionMessage('Retry in a few seconds');

        $this->drafter($server)->save($this->mailUser(), 1, $this->mail());
    }

    public function testAUserWithoutMailboxAccessIsDenied(): void
    {
        $user = $this->mailUser();
        $user->setSendOnly(true);
        $server = new FakeImapServer([]);

        try {
            $this->drafter($server)->save($user, 1, $this->mail());
            self::fail('Expected a denial.');
        } catch (MailboxDeniedException) {
            self::assertSame(0, $server->connections);
        }
    }

    public function testADisabledUserIsDeniedBeforeTheLimitIsCounted(): void
    {
        $user = $this->mailUser();
        $user->setEnabled(false);
        $limiter = $this->limiter(1);

        try {
            $this->drafterWith(new FakeImapServer([]), $limiter)->save($user, 1, $this->mail());
            self::fail('Expected a denial.');
        } catch (MailboxDeniedException) {
        }

        $server = new FakeImapServer(['TAG1 OK Logged in', '* LIST (\Drafts) "/" Drafts', 'TAG2 OK Listed', '+ Ready', 'TAG3 OK Appended']);
        self::assertSame('Drafts', $this->drafterWith($server, $limiter)->save($this->mailUser(), 1, $this->mail()));
    }

    public function testTheTokenLimitIsEnforcedBeforeAnythingIsTried(): void
    {
        $script = ['TAG1 OK Logged in', '* LIST (\Drafts) "/" Drafts', 'TAG2 OK Listed', '+ Ready', 'TAG3 OK Appended'];
        $first = new FakeImapServer($script);
        $limiter = $this->limiter(1);
        $drafter = $this->drafterWith($first, $limiter);
        $drafter->save($this->mailUser(), 5, $this->mail());

        $second = new FakeImapServer($script);

        try {
            $this->drafterWith($second, $limiter)->save($this->mailUser(), 5, $this->mail());
            self::fail('Expected the limit to apply.');
        } catch (MailDraftRateLimitedException) {
            self::assertSame(0, $second->connections);
        }
    }

    private function drafter(FakeImapServer $server, int $limit = 100): MailDrafter
    {
        return $this->drafterWith($server, $this->limiter($limit));
    }

    private function drafterWith(FakeImapServer $server, MailDraftRateLimiter $limiter): MailDrafter
    {
        return new MailDrafter($this->sessions($server), $limiter, new NullLogger());
    }

    private function limiter(int $limit): MailDraftRateLimiter
    {
        return Limiters::draft($limit);
    }

    private function mail(): OutgoingMail
    {
        $email = new Email()->from('alice@example.com')->to('bob@example.com')->subject('Hello')->text('sentinel body');

        return new OutgoingMail($email, '<id@example.com>', ['bob@example.com']);
    }
}

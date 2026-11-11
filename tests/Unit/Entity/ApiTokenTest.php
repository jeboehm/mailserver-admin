<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Entity;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\ApiToken\SenderBinding;
use App\Service\ApiToken\SenderMode;
use PHPUnit\Framework\TestCase;

class ApiTokenTest extends TestCase
{
    public function testHoldsTheHashOfTheSecret(): void
    {
        // printf 'secret' | sha256sum
        self::assertSame('2bb80d537b1da3e38bd30361aa855686bde0eacd7162fef6a25fe97bf527a25b', ApiToken::hashSecret('secret'));
    }

    public function testKnowsItsScopes(): void
    {
        $token = $this->token([ApiTokenScope::MailRead, ApiTokenScope::ManageRead]);

        self::assertTrue($token->hasScope(ApiTokenScope::MailRead));
        self::assertFalse($token->hasScope(ApiTokenScope::MailSend));
        self::assertSame([ApiTokenScope::MailRead, ApiTokenScope::ManageRead], $token->getScopes());
        self::assertSame('mail:read, manage:read', $token->getScopeNames());
    }

    public function testAScopeNoLongerKnownGrantsNothing(): void
    {
        $token = $this->token([ApiTokenScope::MailRead]);
        new \ReflectionProperty(ApiToken::class, 'scopes')->setValue($token, ['mail:read', 'retired:scope']);

        self::assertSame([ApiTokenScope::MailRead], $token->getScopes());
    }

    public function testIsActiveUntilItExpires(): void
    {
        self::assertTrue($this->token(expiresAt: '+1 minute')->isActive());

        $expired = $this->token(expiresAt: '-1 minute');
        self::assertTrue($expired->isExpired());
        self::assertFalse($expired->isActive());
    }

    public function testRevokingIsPermanentAndKeepsTheFirstDate(): void
    {
        $token = $this->token();

        self::assertFalse($token->isRevoked());

        $token->revoke();
        $revokedAt = $token->getRevokedAt();
        $token->revoke();

        self::assertTrue($token->isRevoked());
        self::assertFalse($token->isActive());
        self::assertSame($revokedAt, $token->getRevokedAt());
    }

    public function testTheFirstUseIsAlwaysRecorded(): void
    {
        $token = $this->token();
        $now = new \DateTimeImmutable('2026-01-01 12:00:00');

        self::assertTrue($token->markUsed($now));
        self::assertSame($now, $token->getLastUsedAt());
    }

    public function testFurtherUseIsRecordedOncePerMinute(): void
    {
        $token = $this->token();
        $first = new \DateTimeImmutable('2026-01-01 12:00:00');
        $token->markUsed($first);

        self::assertFalse($token->markUsed($first->modify('+59 seconds')));
        self::assertSame($first, $token->getLastUsedAt());

        $later = $first->modify('+60 seconds');
        self::assertTrue($token->markUsed($later));
        self::assertSame($later, $token->getLastUsedAt());
    }

    public function testSendsAsThePrimaryAddressByDefault(): void
    {
        self::assertEquals(SenderBinding::primary(), $this->token([ApiTokenScope::MailSend])->getSender());
    }

    public function testKeepsTheChosenSender(): void
    {
        $token = new ApiToken(new User(), 'test', ApiToken::hashSecret('secret'), [ApiTokenScope::MailDraft], new \DateTimeImmutable('+1 day'), new SenderBinding(SenderMode::Fixed, 'Info@Example.com'));

        self::assertEquals(new SenderBinding(SenderMode::Fixed, 'info@example.com'), $token->getSender());
    }

    public function testHasNoSenderWithoutSendingOrDrafting(): void
    {
        $token = new ApiToken(new User(), 'test', ApiToken::hashSecret('secret'), [ApiTokenScope::MailRead, ApiTokenScope::ManageWrite], new \DateTimeImmutable('+1 day'), new SenderBinding(SenderMode::Any));

        self::assertNull($token->getSender());
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private function token(array $scopes = [], string $expiresAt = '+1 day'): ApiToken
    {
        return new ApiToken(new User(), 'test', ApiToken::hashSecret('secret'), $scopes, new \DateTimeImmutable($expiresAt));
    }
}

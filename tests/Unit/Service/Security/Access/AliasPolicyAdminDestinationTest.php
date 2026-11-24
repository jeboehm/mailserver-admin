<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\Alias;
use App\Repository\UserRepository;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\AliasPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A domain admin must not forward mail to an admin's address (follow-up to issue #576).
 */
class AliasPolicyAdminDestinationTest extends TestCase
{
    use ActorFixturesTrait;

    public function testOnlyAnAdminMayForwardToAnAdminAddress(): void
    {
        $domain = $this->domain();
        $policy = $this->policyWithAdminAddress('boss@example.com');

        self::assertTrue($policy->canUseDestination($this->actor(ActorRole::Admin, $domain), 'boss@example.com'));
        self::assertFalse($policy->canUseDestination($this->actor(ActorRole::DomainAdmin, $domain), 'boss@example.com'));
        self::assertFalse($policy->canUseDestination($this->actor(ActorRole::DomainAdmin, $domain), 'BOSS@Example.com'));
    }

    public function testOtherDestinationsStayOpenToTheDomainAdmin(): void
    {
        $domain = $this->domain();
        $policy = $this->policyWithAdminAddress('boss@example.com');

        self::assertTrue($policy->canUseDestination($this->actor(ActorRole::DomainAdmin, $domain), 'sales@example.com'));
        self::assertTrue($policy->canUseDestination($this->actor(ActorRole::DomainAdmin, $domain), 'boss@elsewhere.example.net'));
    }

    #[DataProvider('extensionProvider')]
    public function testAnAddressExtensionDoesNotHideAnAdminAddress(string $delimiter, string $destination, bool $allowed): void
    {
        $domain = $this->domain();
        $policy = $this->policyWithAdminAddress('boss@example.com', $delimiter);

        self::assertSame($allowed, $policy->canUseDestination($this->actor(ActorRole::DomainAdmin, $domain), $destination));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function extensionProvider(): iterable
    {
        yield 'default delimiter' => ['-', 'boss-shop@example.com', false];
        yield 'extension with several delimiters' => ['-', 'boss-shop-two@example.com', false];
        yield 'plus delimiter' => ['+', 'boss+shop@example.com', false];
        yield 'one of several delimiter characters' => ['+-', 'boss+shop@example.com', false];
        yield 'extension of another user' => ['-', 'sales-shop@example.com', true];
        yield 'delimiter is not configured' => ['+', 'boss-shop@example.com', true];
        yield 'extensions are off' => ['', 'boss+shop@example.com', true];
        yield 'leading delimiter' => ['-', '-boss@example.com', true];
    }

    public function testModifyAndDeleteFollowTheStoredDestination(): void
    {
        $domain = $this->domain();
        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName('sales');
        $alias->setDestination('boss@example.com');
        $policy = $this->policyWithAdminAddress('boss@example.com');
        $domainAdmin = $this->actor(ActorRole::DomainAdmin, $domain);

        self::assertFalse($policy->canModify($domainAdmin, $alias));
        self::assertFalse($policy->canDelete($domainAdmin, $alias));
        self::assertTrue($policy->canModify($this->actor(ActorRole::Admin, $domain), $alias));
    }

    public function testDenyThrowsForADomainAdmin(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->policyWithAdminAddress('boss@example.com')->denyUnlessCanUseDestination($this->actor(ActorRole::DomainAdmin, $this->domain()), 'boss@example.com');
    }

    private function policyWithAdminAddress(string $address, string $delimiter = '-'): AliasPolicy
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('isAdminEmailAddress')->willReturnCallback(static fn (string $candidate): bool => $candidate === $address);

        return new AliasPolicy($users, $delimiter);
    }
}

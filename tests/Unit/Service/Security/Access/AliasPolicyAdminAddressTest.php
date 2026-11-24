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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AliasPolicyAdminAddressTest extends TestCase
{
    use ActorFixturesTrait;

    public function testOnlyAnAdminMayUseAnAdminAddress(): void
    {
        $domain = $this->domain();
        $policy = $this->policyWithAdminAddress('boss');

        self::assertTrue($policy->canUseAddress($this->actor(ActorRole::Admin, $domain), $domain, 'boss'));
        self::assertFalse($policy->canUseAddress($this->actor(ActorRole::DomainAdmin, $domain), $domain, 'boss'));
        self::assertFalse($policy->canUseAddress($this->actor(ActorRole::User, $domain), $domain, 'boss'));
    }

    public function testOtherAddressesStayOpenToTheDomainAdmin(): void
    {
        $domain = $this->domain();

        self::assertTrue($this->policyWithAdminAddress('boss')->canUseAddress($this->actor(ActorRole::DomainAdmin, $domain), $domain, 'sales'));
    }

    public function testModifyAndDeleteFollowTheAddressOfTheAlias(): void
    {
        $domain = $this->domain();
        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName('boss');
        $policy = $this->policyWithAdminAddress('boss');
        $domainAdmin = $this->actor(ActorRole::DomainAdmin, $domain);

        self::assertFalse($policy->canModify($domainAdmin, $alias));
        self::assertFalse($policy->canDelete($domainAdmin, $alias));
        self::assertTrue($policy->canModify($this->actor(ActorRole::Admin, $domain), $alias));
    }

    public function testDenyThrowsForADomainAdmin(): void
    {
        $domain = $this->domain();

        $this->expectException(AccessDeniedException::class);

        $this->policyWithAdminAddress('boss')->denyUnlessCanUseAddress($this->actor(ActorRole::DomainAdmin, $domain), $domain, 'boss');
    }

    private function policyWithAdminAddress(string $name): AliasPolicy
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('isAdminAddress')->willReturnCallback(static fn (string $localPart): bool => $localPart === $name);

        return new AliasPolicy($users);
    }
}

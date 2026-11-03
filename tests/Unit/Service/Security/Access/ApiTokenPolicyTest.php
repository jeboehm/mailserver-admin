<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\ApiTokenPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ApiTokenPolicyTest extends TestCase
{
    use ActorFixturesTrait;

    public function testAdminMayRevokeAnyToken(): void
    {
        $actor = $this->actor(ActorRole::Admin, $this->domain());

        self::assertTrue(new ApiTokenPolicy()->canRevoke($actor, $this->tokenOf($this->user($this->domain('foreign.invalid')))));
    }

    public function testDomainAdminMayRevokeTokensInTheirDomainOnly(): void
    {
        $own = $this->domain();
        $actor = $this->actor(ActorRole::DomainAdmin, $own);
        $policy = new ApiTokenPolicy();

        self::assertTrue($policy->canRevoke($actor, $this->tokenOf($this->user($own))));
        self::assertFalse($policy->canRevoke($actor, $this->tokenOf($this->user($this->domain('foreign.invalid')))));
    }

    public function testUserMayRevokeOnlyTheirOwnToken(): void
    {
        $own = $this->domain();
        $actor = $this->actor(ActorRole::User, $own);
        $policy = new ApiTokenPolicy();

        self::assertTrue($policy->canRevoke($actor, $this->tokenOf($actor->user)));
        self::assertFalse($policy->canRevoke($actor, $this->tokenOf($this->user($own))));
    }

    public function testDenyThrows(): void
    {
        $own = $this->domain();
        $actor = new Actor($this->user($own), ActorRole::User);

        $this->expectException(AccessDeniedException::class);

        new ApiTokenPolicy()->denyUnlessCanRevoke($actor, $this->tokenOf($this->user($own)));
    }

    private function tokenOf(User $owner): ApiToken
    {
        return new ApiToken($owner, 'test', ApiToken::hashSecret('secret'), [ApiTokenScope::ManageRead], new \DateTimeImmutable('+1 day'));
    }
}

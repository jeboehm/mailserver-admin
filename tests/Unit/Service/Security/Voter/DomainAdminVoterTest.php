<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Voter;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\User;
use App\Service\Security\Roles;
use App\Service\Security\Voter\DomainAdminVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class DomainAdminVoterTest extends TestCase
{
    private DomainAdminVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new DomainAdminVoter();
    }

    public function testVoteAbstainsOnUnsupportedAttribute(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $subject = new User();

        $result = $this->voter->vote($token, $subject, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteAbstainsOnUnsupportedSubject(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $subject = new \stdClass();

        $result = $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteGrantsOnNullSubject(): void
    {
        $token = $this->createMock(TokenInterface::class);
        // Voter::vote calls supports() then voteOnAttribute().
        // supports handles null subject.

        $result = $this->voter->vote($token, null, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteGrantsForAdminRole(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_ADMIN]);

        $subject = new User();

        $result = $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteGrantsForDomainAdminWithMatchingDomain(): void
    {
        $domain = new Domain();

        $user = $this->createMock(User::class);
        $user->method('getDomain')->willReturn($domain);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn($user);

        $subject = $this->createMock(User::class);
        $subject->method('getDomain')->willReturn($domain);

        $result = $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesForDomainAdminWithMismatchingDomain(): void
    {
        $domain1 = new Domain();
        $domain2 = new Domain();

        $user = $this->createMock(User::class);
        $user->method('getDomain')->willReturn($domain1);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn($user);

        $subject = $this->createMock(User::class);
        $subject->method('getDomain')->willReturn($domain2);

        $result = $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteDeniesForUserWithoutRoles(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_USER]);

        $subject = new User();

        $result = $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteThrowsExceptionWhenTokenUserIsNotUserEntity(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));

        $subject = new User();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('User is not an instance of User');

        $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);
    }

    public function testVoteGrantsForDomainAdminWithMatchingDomainAliasSubject(): void
    {
        $domain = new Domain();

        $user = $this->createMock(User::class);
        $user->method('getDomain')->willReturn($domain);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn($user);

        $subject = $this->createMock(Alias::class);
        $subject->method('getDomain')->willReturn($domain);

        $result = $this->voter->vote($token, $subject, [DomainAdminVoter::VIEW]);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }
}

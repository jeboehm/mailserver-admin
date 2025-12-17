<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Voter;

use App\Entity\Domain;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Service\Security\Roles;
use App\Service\Security\Voter\FetchmailAccountVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class FetchmailAccountVoterTest extends TestCase
{
    private FetchmailAccountVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new FetchmailAccountVoter();
    }

    public function testVoteAbstainsOnWrongAttribute(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $subject = $this->createStub(FetchmailAccount::class);

        $result = $this->voter->vote($token, $subject, ['WRONG_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteAbstainsOnWrongSubject(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $subject = new \stdClass();

        $result = $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteGrantsAccessOnNullSubject(): void
    {
        $token = $this->createStub(TokenInterface::class);

        // When subject is null, voteOnAttribute returns true.
        $result = $this->voter->vote($token, null, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteGrantsAccessForAdmin(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_ADMIN]);

        $subject = $this->createStub(FetchmailAccount::class);

        $result = $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteGrantsAccessForDomainAdminSameDomain(): void
    {
        $domain = $this->createStub(Domain::class);

        $user = $this->createStub(User::class);
        $user->method('getDomain')->willReturn($domain);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn($user);

        $accountUser = $this->createStub(User::class);
        $accountUser->method('getDomain')->willReturn($domain);

        $subject = $this->createStub(FetchmailAccount::class);
        $subject->method('getUser')->willReturn($accountUser);

        $result = $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesAccessForDomainAdminDifferentDomain(): void
    {
        $domain1 = $this->createStub(Domain::class);
        $domain2 = $this->createStub(Domain::class);

        $user = $this->createStub(User::class);
        $user->method('getDomain')->willReturn($domain1);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn($user);

        $accountUser = $this->createStub(User::class);
        $accountUser->method('getDomain')->willReturn($domain2);

        $subject = $this->createStub(FetchmailAccount::class);
        $subject->method('getUser')->willReturn($accountUser);

        $result = $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteThrowsLogicExceptionIfDomainAdminIsNotUserInstance(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_DOMAIN_ADMIN]);
        $token->method('getUser')->willReturn(null); // Not a User instance

        $subject = $this->createStub(FetchmailAccount::class);

        $this->expectException(\LogicException::class);
        $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);
    }

    public function testVoteGrantsAccessForOwner(): void
    {
        $user = $this->createStub(User::class);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_USER]);
        $token->method('getUser')->willReturn($user);

        $subject = $this->createStub(FetchmailAccount::class);
        $subject->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesAccessForNonOwner(): void
    {
        $user1 = $this->createStub(User::class);
        $user2 = $this->createStub(User::class);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn([Roles::ROLE_USER]);
        $token->method('getUser')->willReturn($user1);

        $subject = $this->createStub(FetchmailAccount::class);
        $subject->method('getUser')->willReturn($user2);

        $result = $this->voter->vote($token, $subject, [FetchmailAccountVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Voter;

use App\Entity\User;
use App\Service\Security\Voter\LocalUserVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class LocalUserVoterTest extends TestCase
{
    private LocalUserVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new LocalUserVoter();
    }

    public function testVoteAbstainsOnUnsupportedAttribute(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $subject = new User();

        $result = $this->voter->vote($token, $subject, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertEquals(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteGrantsAccessWhenSubjectIsNullAndTokenUserIsUserInstance(): void
    {
        $user = new User();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, null, [LocalUserVoter::KEY]);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesAccessWhenSubjectIsNullAndTokenUserIsNotUserInstance(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createStub(UserInterface::class));

        $result = $this->voter->vote($token, null, [LocalUserVoter::KEY]);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteDeniesAccessWhenSubjectIsNullAndTokenUserIsNull(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, null, [LocalUserVoter::KEY]);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Voter;

use App\Entity\FetchmailAccount;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\FetchmailAccountPolicy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * FetchmailAccountPolicy::canManage() as a permission, for EasyAdmin's edit and delete actions: that also
 * hides them and refuses the request before a form touches the entity.
 *
 * @extends Voter<string, FetchmailAccount>
 */
final class FetchmailAccountManageVoter extends Voter
{
    public const string MANAGE = 'fetchmail_account_manage';

    public function __construct(
        private readonly FetchmailAccountPolicy $policy,
        private readonly CurrentActor $currentActor,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof FetchmailAccount;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->policy->canManage($this->currentActor->get(), $subject);
    }
}

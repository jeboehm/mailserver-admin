<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Voter;

use App\Entity\User;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\UserPolicy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * UserPolicy::canModify() as a permission, for EasyAdmin's edit action: that also hides the action,
 * disables the switches on the list and refuses the request before a form touches the entity.
 *
 * @extends Voter<string, User>
 */
final class UserModifyVoter extends Voter
{
    public const string MODIFY = 'user_modify';

    public function __construct(
        private readonly UserPolicy $userPolicy,
        private readonly CurrentActor $currentActor,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MODIFY === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->userPolicy->canModify($this->currentActor->get(), $subject);
    }
}

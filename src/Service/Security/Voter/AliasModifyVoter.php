<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Voter;

use App\Entity\Alias;
use App\Service\Security\Access\AliasPolicy;
use App\Service\Security\Access\CurrentActor;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * AliasPolicy::canModify() as a permission, for EasyAdmin's edit action: that also hides the action
 * and refuses the request before a form touches the entity.
 *
 * @extends Voter<string, Alias>
 */
final class AliasModifyVoter extends Voter
{
    public const string MODIFY = 'alias_modify';

    public function __construct(
        private readonly AliasPolicy $aliasPolicy,
        private readonly CurrentActor $currentActor,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MODIFY === $attribute && $subject instanceof Alias;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->aliasPolicy->canModify($this->currentActor->get(), $subject);
    }
}

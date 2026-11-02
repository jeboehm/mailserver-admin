<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\Alias;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AliasPolicy
{
    public function canDelete(Actor $actor, Alias $alias): bool
    {
        return $actor->managesDomain($alias->getDomain());
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanDelete(Actor $actor, Alias $alias): void
    {
        if (!$this->canDelete($actor, $alias)) {
            throw new AccessDeniedException('This alias cannot be deleted.');
        }
    }
}

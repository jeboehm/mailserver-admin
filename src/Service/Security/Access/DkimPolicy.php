<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class DkimPolicy
{
    public function canRecreateKey(Actor $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanRecreateKey(Actor $actor): void
    {
        if (!$this->canRecreateKey($actor)) {
            throw new AccessDeniedException('Only admins may recreate a DKIM key.');
        }
    }
}

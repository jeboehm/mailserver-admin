<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class PasswordService
{
    public function __construct(private readonly PasswordHasherFactoryInterface $passwordHasherFactory)
    {
    }

    public function processUserPassword(User $user): void
    {
        if (null !== $user->getPlainPassword()) {
            $passwordHasher = $this->passwordHasherFactory->getPasswordHasher($user);
            $user->setPassword($passwordHasher->hash($user->getPlainPassword()));
        }
    }
}

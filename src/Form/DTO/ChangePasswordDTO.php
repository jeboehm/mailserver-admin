<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\DTO;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordDTO
{
    #[UserPassword(message: 'The password is wrong. Please enter your current password.')]
    #[Assert\NotBlank]
    public string $currentPassword;

    #[Assert\NotBlank]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_WEAK)]
    #[Assert\NotCompromisedPassword(skipOnError: true)]
    #[Assert\NotEqualTo(propertyPath: 'currentPassword', message: 'This password is the same as the current password. Please use another password.')]
    public string $newPassword;
}

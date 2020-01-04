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
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;

class PasswordService
{
    /**
     * @var int
     */
    private const SALT_LENGTH = 16;

    private EncoderFactoryInterface $encoderFactory;

    public function __construct(EncoderFactoryInterface $encoderFactory)
    {
        $this->encoderFactory = $encoderFactory;
    }

    public function processUserPassword(User $user): void
    {
        if (null !== $user->getPlainPassword()) {
            $encoder = $this->encoderFactory->getEncoder($user);
            $user->setPassword(
                $encoder->encodePassword(
                    $user->getPlainPassword(),
                    substr(sha1(\random_bytes(50)), 0, self::SALT_LENGTH)
                )
            );
        }
    }
}

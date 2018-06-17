<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Service;

use App\Entity\User;
use App\Security\Encoder\DefaultPasswordEncoder;
use App\Service\PasswordService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Encoder\EncoderFactory;

class PasswordServiceTest extends TestCase
{
    public function testProcessUserPassword(): void
    {
        $factory = new EncoderFactory([User::class => new DefaultPasswordEncoder()]);
        $service = new PasswordService($factory);

        $user = new User();
        $user->setPlainPassword('test1234');

        $service->processUserPassword($user);
        $this->assertNotEmpty($user->getPassword());
    }
}

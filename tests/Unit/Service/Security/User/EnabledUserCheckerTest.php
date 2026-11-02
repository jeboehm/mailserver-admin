<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\User;

use App\Entity\User;
use App\Service\Security\User\EnabledUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserInterface;

class EnabledUserCheckerTest extends TestCase
{
    public function testDisabledUserIsRejected(): void
    {
        $user = new User();
        $user->setEnabled(false);

        $this->expectException(DisabledException::class);

        new EnabledUserChecker()->checkPreAuth($user);
    }

    public function testEnabledUserIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        new EnabledUserChecker()->checkPreAuth(new User());
    }

    public function testOtherUserClassesAreIgnored(): void
    {
        $this->expectNotToPerformAssertions();

        new EnabledUserChecker()->checkPreAuth($this->createStub(UserInterface::class));
    }
}

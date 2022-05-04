<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Integration\Helper;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait UserTrait
{
    private function loginClient(KernelBrowser $client, string $username = 'admin@example.com'): void
    {
        $client->followRedirects();

        $user = $client->getContainer()->get(UserRepository::class)->findOneByEmailAddress($username);
        $client->loginUser($user);
    }
}

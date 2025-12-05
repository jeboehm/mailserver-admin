<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\UserTrait;

class DashboardControllerTest extends WebTestCase
{
    use UserTrait;

    public function testShowDashboard(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.user-name', 'admin@example.com');

        self::assertSelectorTextContains('h2', 'Welcome to the admin dashboard!');
    }
}

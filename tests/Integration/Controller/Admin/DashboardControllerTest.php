<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Integration\Controller\Admin;

use App\Tests\Integration\Helper\UserTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    use UserTrait;

    public function testShowDashboard(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.user-name', 'admin@example.com');

        self::assertSelectorTextContains('[data-action-name="new"]', 'Add Domain');
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'admin@example.com',
            '_password' => 'changeme',
        ]);

        self::assertResponseRedirects('http://localhost/', Response::HTTP_FOUND);
    }
}

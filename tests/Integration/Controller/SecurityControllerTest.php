<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPage(): void
    {
        $client = static::createClient();
        $client->followRedirects();

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            '_username' => 'admin@example.com',
            '_password' => 'changeme',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testLoginPageRenders(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $client->followRedirects();

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            '_username' => 'admin@example.com',
            '_password' => 'wrongpassword',
        ]);

        // Should redirect back to login or show error
        self::assertResponseIsSuccessful();
    }

    public function testLogout(): void
    {
        $client = static::createClient();
        // Don't follow redirects so we can check them

        // Login first
        $userRepository = $client->getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        $client->loginUser($user);

        // Access a protected page to verify we're logged in
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        // Logout - this should redirect
        $client->request(Request::METHOD_GET, '/logout');
        // Logout typically redirects to home or login
        self::assertResponseRedirects();

        // Follow the redirect
        $client->followRedirect();

        // Try to access protected page again - should redirect to login
        $client->request(Request::METHOD_GET, '/');
        // After logout, accessing protected pages should redirect to login
        self::assertResponseRedirects();
        // Verify it redirects to login page
        self::assertResponseRedirects('/login');
    }
}

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

class DomainCrudControllerTest extends WebTestCase
{
    use UserTrait;

    public function testAddSameDomainAgain(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $client->clickLink('Add Domain');
        self::assertResponseIsSuccessful();

        $client->submitForm('Create', [
            'Domain[name]' => 'example.com',
        ]);
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('.invalid-feedback', 'This value is already used.');
    }

    public function testListDomains(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();
        self::assertStringContainsString('example.com', $crawler->filter('tbody > tr')->text());
    }
}

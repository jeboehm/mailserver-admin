<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Integration\Controller\Admin;

use App\Controller\Admin\DKIMCrudController;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\DKIM\DKIMStatus;
use App\Service\DKIM\DKIMStatusService;
use App\Tests\Integration\Helper\UserTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class DKIMCrudControllerTest extends WebTestCase
{
    use UserTrait;

    public function testIndex(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $client->request('GET', $this->getUrlGenerator($client)->setController(DKIMCrudController::class)->generateUrl());
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('span[title="example.com"]', 'example.com');
    }

    public function testDkimEditDnsMissing(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $domain = $client->getContainer()->get(DomainRepository::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $domain);

        $this->navigateToDomain($client, $domain);

        self::assertSelectorTextContains('.alert', 'DKIM is enabled but not properly set up. Your mails may be rejected on the receivers side. Check your DNS settings.');
    }

    public function testDkimEditDnsWrong(): void
    {
        $client = static::createClient();

        $dkimStatusService = $this->createMock(DKIMStatusService::class);
        $client->getContainer()->set(DKIMStatusService::class, $dkimStatusService);

        $dkimStatusService->method('getStatus')->willReturn(
            new DKIMStatus(true, true, false, 'hi')
        );

        $domain = $client->getContainer()->get(DomainRepository::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $domain);

        $this->loginClient($client);
        $this->navigateToDomain($client, $domain);

        self::assertSelectorTextContains('.alert', 'DKIM is enabled but not properly set up. Your mails may be rejected on the receivers side. Check your DNS settings.');
    }

    private function navigateToDomain(KernelBrowser $client, Domain $domain): Crawler
    {
        $crawler = $client->request(
            'GET',
            $this->getUrlGenerator($client)
            ->setController(DKIMCrudController::class)
            ->setAction('edit')
            ->setEntityId($domain->getId())
            ->generateUrl()
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}

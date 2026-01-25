<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\DomainCrudController;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\UserTrait;

class DomainCrudControllerTest extends AbstractCrudTestCase
{
    use UserTrait;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginClient($this->client);
    }

    public function testAddSameDomainAgain(): void
    {
        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Domain');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'Domain[name]' => 'example.com',
        ]);

        static::assertSelectorTextContains('.invalid-feedback', 'This value is already used.');
    }

    public function testNewDomain(): void
    {
        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Domain');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'Domain[name]' => 'example-new.com',
        ]);
        static::assertResponseIsSuccessful();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();
        static::assertSelectorTextContains('span[title="example-new.com"]', 'example-new.com');
    }

    public function testListDomains(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        $domainId = $domain->getId();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        static::assertSelectorTextContains('span[title="example.com"]', 'example.com');

        $this->assertIndexEntityActionNotExists('delete', $domainId);
        $this->assertGlobalActionExists('new');
    }

    public function testListCanDeleteEmptyDomains(): void
    {
        $domain = new Domain();
        $domain->setName('example.invalid');

        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        $domainId = $domain->getId();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        static::assertSelectorTextContains('span[title="example.invalid"]', 'example.invalid');

        $this->assertIndexEntityActionExists('delete', $domainId);
        $this->assertGlobalActionExists('new');
    }

    protected function getControllerFqcn(): string
    {
        return DomainCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }
}

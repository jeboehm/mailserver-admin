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
use App\Repository\DomainRepository;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
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
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
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
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Domain');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'Domain[name]' => 'example-new.com',
        ]);
        static::assertResponseIsSuccessful();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();
        static::assertSelectorTextContains('span[title="example-new.com"]', 'example-new.com');
    }

    public function testListDomains(): void
    {
        $domainRepository = $this->entityManager->getRepository(\App\Entity\Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(\App\Entity\Domain::class, $domain);
        $domainId = $domain->getId();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        static::assertSelectorTextContains('span[title="example.com"]', 'example.com');

        $this->assertIndexEntityActionExists('edit', $domainId);
        $this->assertIndexEntityActionExists('delete', $domainId);
        $this->assertGlobalActionExists('new');
    }

    public function testEditDomain(): void
    {
        $domainRepository = $this->entityManager->getRepository(\App\Entity\Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(\App\Entity\Domain::class, $domain);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateEditFormUrl($domain->getId()));
        static::assertResponseIsSuccessful();

        // Domain name should not be editable (it's the primary key)
        // But we can verify the page loads correctly
        static::assertSelectorTextContains('h1', 'Edit Domain');
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

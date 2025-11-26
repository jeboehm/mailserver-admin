<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\Admin;

use App\Controller\Admin\AliasCrudController;
use App\Controller\Admin\DashboardController;
use App\Entity\Alias;
use App\Entity\Domain;
use App\Repository\AliasRepository;
use App\Repository\DomainRepository;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Tests\Integration\Helper\UserTrait;

class AliasCrudControllerTest extends AbstractCrudTestCase
{
    use UserTrait;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginClient($this->client);
    }

    public function testListAliases(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName('test-action');
        $alias->setDestination('test@example.com');
        $this->entityManager->persist($alias);
        $this->entityManager->flush();
        $aliasId = $alias->getId();
        $this->entityManager->clear();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->assertIndexEntityActionExists('edit', $aliasId);
        $this->assertIndexEntityActionExists('delete', $aliasId);
        $this->assertGlobalActionExists('new');
    }

    public function testCreateAlias(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Alias');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'Alias[name]' => 'testalias',
            'Alias[destination]' => 'test@example.com',
        ]);
        static::assertResponseIsSuccessful();

        $aliasRepository = $this->entityManager->getRepository(Alias::class);
        assert($aliasRepository instanceof AliasRepository);
        $alias = $aliasRepository->findOneBy(['name' => 'testalias', 'domain' => $domain]);
        static::assertInstanceOf(Alias::class, $alias);
        static::assertEquals('test@example.com', $alias->getDestination());
    }

    public function testCreateCatchAllAlias(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Alias');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'Alias[name]' => '',
            'Alias[destination]' => 'catchall@example.com',
        ]);
        static::assertResponseIsSuccessful();

        $aliasRepository = $this->entityManager->getRepository(Alias::class);
        assert($aliasRepository instanceof AliasRepository);
        $alias = $aliasRepository->findOneBy(['name' => '', 'domain' => $domain, 'destination' => 'catchall@example.com']);
        static::assertInstanceOf(Alias::class, $alias);
    }

    public function testEditAlias(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName('editalias');
        $alias->setDestination('original@example.com');

        $this->entityManager->persist($alias);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateEditFormUrl($alias->getId()));
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Save changes', [
            'Alias[destination]' => 'updated@example.com',
        ]);
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $aliasRepository = $this->entityManager->getRepository(Alias::class);
        assert($aliasRepository instanceof AliasRepository);
        $updatedAlias = $aliasRepository->find($alias->getId());
        static::assertInstanceOf(Alias::class, $updatedAlias);
        static::assertEquals('updated@example.com', $updatedAlias->getDestination());
    }

    public function testCreateDuplicateAlias(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $existingAlias = new Alias();
        $existingAlias->setDomain($domain);
        $existingAlias->setName('duplicate');
        $existingAlias->setDestination('existing@example.com');

        $this->entityManager->persist($existingAlias);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Alias');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'Alias[name]' => 'duplicate',
            'Alias[destination]' => 'existing@example.com',
        ]);

        static::assertSelectorTextContains('.invalid-feedback', 'This value is already used.');
    }

    protected function getControllerFqcn(): string
    {
        return AliasCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }
}

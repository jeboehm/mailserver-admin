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
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\CsrfTokenTrait;
use Tests\Integration\Helper\UserTrait;

/**
 * A domain admin must not take over an admin's mail through an alias to the admin's address (follow-up to issue #576).
 *
 * @extends AbstractCrudTestCase<AliasCrudController>
 */
class AliasCrudControllerAdminDestinationTest extends AbstractCrudTestCase
{
    use CsrfTokenTrait;
    use UserTrait;

    private const string DESTINATION = 'target@elsewhere.example.net';

    private Domain $domain;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        $this->domain = $domain;

        $boss = new User();
        $boss->setDomain($domain);
        $boss->setName('boss');
        $boss->setDomainAdmin(true);
        $boss->setPlainPassword('password123');
        $this->entityManager->persist($boss);
        $this->entityManager->flush();
    }

    public function testDomainAdminCannotCreateAnAliasToAnAdminAddress(): void
    {
        $this->loginClient($this->client, 'boss@example.com');

        $this->client->request(Request::METHOD_GET, $this->generateNewFormUrl());
        $this->client->submitForm('Create', ['Alias[name]' => 'sales', 'Alias[destination]' => 'admin@example.com']);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertNull($this->entityManager->getRepository(Alias::class)->findOneBy(['name' => 'sales']));
    }

    public function testDomainAdminCannotHideAnAdminAddressBehindAnExtension(): void
    {
        $this->loginClient($this->client, 'boss@example.com');

        $this->client->request(Request::METHOD_GET, $this->generateNewFormUrl());
        $this->client->submitForm('Create', ['Alias[name]' => 'sales', 'Alias[destination]' => 'admin-shop@example.com']);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDomainAdminCannotRetargetAnAliasToAnAdminAddress(): void
    {
        $id = $this->createAlias('harmless', self::DESTINATION);
        $this->loginClient($this->client, 'boss@example.com');

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($id));
        $this->client->submitForm('Save changes', ['Alias[destination]' => 'admin@example.com']);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertSame(self::DESTINATION, $this->entityManager->find(Alias::class, $id)?->getDestination());
    }

    public function testDomainAdminCannotEditAnAliasToAnAdminAddress(): void
    {
        $id = $this->createAlias('harmless', 'admin@example.com');
        $this->loginClient($this->client, 'boss@example.com');

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($id));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDomainAdminCannotDeleteAnAliasToAnAdminAddress(): void
    {
        $id = $this->createAlias('harmless', 'admin@example.com');
        $this->loginClient($this->client, 'boss@example.com');
        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), 'ea-delete');

        $this->client->request(Request::METHOD_POST, $this->getCrudUrl(Action::DELETE, $id), ['token' => $token]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertInstanceOf(Alias::class, $this->entityManager->find(Alias::class, $id));
    }

    public function testDomainAdminSeesAProtectedAliasWithoutActions(): void
    {
        $id = $this->createAlias('harmless', 'admin@example.com');
        $this->loginClient($this->client, 'boss@example.com');

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());

        static::assertResponseIsSuccessful();
        $this->assertIndexEntityActionNotExists('edit', $id);
        $this->assertIndexEntityActionNotExists('delete', $id);
    }

    public function testDomainAdminStillManagesAliasesToOtherDestinations(): void
    {
        $id = $this->createAlias('harmless', self::DESTINATION);
        $this->loginClient($this->client, 'boss@example.com');

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($id));
        $this->client->submitForm('Save changes', ['Alias[destination]' => 'other@elsewhere.example.net']);

        static::assertResponseIsSuccessful();
        $this->entityManager->clear();
        static::assertSame('other@elsewhere.example.net', $this->entityManager->find(Alias::class, $id)?->getDestination());
    }

    public function testAdminCanStillCreateAnAliasToAnAdminAddress(): void
    {
        $this->loginClient($this->client);

        $this->client->request(Request::METHOD_GET, $this->generateNewFormUrl());
        $this->client->submitForm('Create', ['Alias[name]' => 'sales', 'Alias[destination]' => 'admin@example.com']);

        static::assertResponseIsSuccessful();
        $this->entityManager->clear();
        static::assertInstanceOf(Alias::class, $this->entityManager->getRepository(Alias::class)->findOneBy(['name' => 'sales']));
    }

    protected function getControllerFqcn(): string
    {
        return AliasCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }

    private function createAlias(string $name, string $destination): int
    {
        $alias = new Alias();
        $alias->setDomain($this->domain);
        $alias->setName($name);
        $alias->setDestination($destination);
        $this->entityManager->persist($alias);
        $this->entityManager->flush();
        $id = (int) $alias->getId();
        $this->entityManager->clear();

        return $id;
    }
}

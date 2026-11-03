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
use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\CsrfTokenTrait;
use Tests\Integration\Helper\PersistedIdTrait;
use Tests\Integration\Helper\UserTrait;

/**
 * @extends AbstractCrudTestCase<DomainCrudController>
 */
class DomainCrudControllerTest extends AbstractCrudTestCase
{
    use CsrfTokenTrait;
    use PersistedIdTrait;
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
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        $domainId = $this->persistedId($domain->getId());

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

        $domainId = $this->persistedId($domain->getId());

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        static::assertSelectorTextContains('span[title="example.invalid"]', 'example.invalid');

        $this->assertIndexEntityActionExists('delete', $domainId);
        $this->assertGlobalActionExists('new');
    }

    public function testCannotDeleteDomainWithUsers(): void
    {
        $domain = $this->createDomain('with-user.invalid');
        $user = new User();
        $user->setDomain($domain);
        $user->setName('max');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $domainId = (int) $domain->getId();
        $userId = (int) $user->getId();

        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), 'ea-delete');
        $this->client->request(Request::METHOD_POST, $this->getCrudUrl(Action::DELETE, $domainId), ['token' => $token]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertInstanceOf(Domain::class, $this->entityManager->find(Domain::class, $domainId));
        static::assertInstanceOf(User::class, $this->entityManager->find(User::class, $userId));
    }

    public function testCannotDeleteDomainWithAliases(): void
    {
        $domain = $this->createDomain('with-alias.invalid');
        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName('info');
        $alias->setDestination('admin@example.com');
        $this->entityManager->persist($alias);
        $this->entityManager->flush();
        $domainId = (int) $domain->getId();
        $aliasId = (int) $alias->getId();

        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), 'ea-delete');
        $this->client->request(Request::METHOD_POST, $this->getCrudUrl(Action::DELETE, $domainId), ['token' => $token]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertInstanceOf(Domain::class, $this->entityManager->find(Domain::class, $domainId));
        static::assertInstanceOf(Alias::class, $this->entityManager->find(Alias::class, $aliasId));
    }

    public function testCannotDeleteNonEmptyDomainThroughBatchDelete(): void
    {
        $domain = $this->createDomain('batch.invalid');
        $user = new User();
        $user->setDomain($domain);
        $user->setName('max');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $domainId = (int) $domain->getId();

        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), 'ea-batch-action-' . Action::BATCH_DELETE . '-' . Domain::class);
        $this->client->request(Request::METHOD_POST, $this->getCrudUrl(Action::BATCH_DELETE), [
            EA::BATCH_ACTION_NAME => Action::BATCH_DELETE,
            EA::BATCH_ACTION_ENTITY_IDS => [$domainId],
            EA::ENTITY_FQCN => Domain::class,
            EA::BATCH_ACTION_CSRF_TOKEN => $token,
        ]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertInstanceOf(Domain::class, $this->entityManager->find(Domain::class, $domainId));
    }

    public function testCanStillDeleteEmptyDomain(): void
    {
        $domainId = (int) $this->createDomain('empty.invalid')->getId();

        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), 'ea-delete');
        $this->client->request(Request::METHOD_POST, $this->getCrudUrl(Action::DELETE, $domainId), ['token' => $token]);

        static::assertResponseIsSuccessful();
        $this->entityManager->clear();
        static::assertNull($this->entityManager->find(Domain::class, $domainId));
    }

    protected function getControllerFqcn(): string
    {
        return DomainCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }

    private function createDomain(string $name): Domain
    {
        $domain = new Domain();
        $domain->setName($name);
        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        return $domain;
    }
}

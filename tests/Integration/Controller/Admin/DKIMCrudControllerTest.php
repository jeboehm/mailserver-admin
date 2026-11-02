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
use App\Controller\Admin\DKIMCrudController;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\DKIM\DKIMStatus;
use App\Service\DKIM\DKIMStatusService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\CsrfTokenTrait;
use Tests\Integration\Helper\UserTrait;

class DKIMCrudControllerTest extends AbstractCrudTestCase
{
    use CsrfTokenTrait;
    use UserTrait;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testIndex(): void
    {
        $this->loginClient($this->client);

        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        $domainId = $domain->getId();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        static::assertSelectorTextContains('span[title="example.com"]', 'example.com');

        $this->assertIndexEntityActionExists('edit', $domainId);
        $this->assertIndexEntityActionNotExists('delete', $domainId);
        $this->assertGlobalActionNotExists('new');
    }

    public function testDkimEditDnsMissing(): void
    {
        $this->loginClient($this->client);
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $this->navigateToDomain($domain);

        static::assertSelectorTextContains('.alert', 'DKIM is enabled but not correctly configured. This may result in your emails being rejected by recipients. Please verify your DNS settings.');
    }

    public function testDkimEditDnsWrong(): void
    {
        $this->client->disableReboot();
        $dkimStatusService = $this->createStub(DKIMStatusService::class);
        $dkimStatusService->method('getStatus')->willReturn(
            new DKIMStatus(true, true, false, 'v=DKIM1; k=rsa; p=...')
        );
        $this->client->getContainer()->set(DKIMStatusService::class, $dkimStatusService);
        $this->loginClient($this->client);

        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        // Ensure DKIM is enabled
        $domain->setDkimEnabled(true);
        $this->entityManager->persist($domain);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->navigateToDomain($domain);

        static::assertSelectorTextContains('.alert', 'DKIM is enabled but not correctly configured. This may result in your emails being rejected by recipients. Please verify your DNS settings.');
    }

    public function testDkimEditDnsCorrect(): void
    {
        $this->client->disableReboot();
        $dkimStatusService = $this->createStub(DKIMStatusService::class);
        $dkimStatusService->method('getStatus')->willReturn(
            new DKIMStatus(true, true, true, 'v=DKIM1; k=rsa; p=...')
        );
        $this->client->getContainer()->set(DKIMStatusService::class, $dkimStatusService);
        $this->loginClient($this->client);

        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        // Ensure DKIM is enabled
        $domain->setDkimEnabled(true);
        $this->entityManager->persist($domain);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->navigateToDomain($domain);

        // When DKIM is correctly configured, there should be a success alert, not a danger alert
        static::assertSelectorTextContains('.alert', 'DKIM is configured correctly and functioning as expected.');
    }

    public function testDkimEditDisabled(): void
    {
        $this->loginClient($this->client);
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        // Ensure DKIM is disabled
        $domain->setDkimEnabled(false);
        $this->entityManager->persist($domain);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->navigateToDomain($domain);

        // When DKIM is disabled, there should be an info alert, not a danger alert about DNS configuration
        static::assertSelectorExists('.alert.alert-info');
        static::assertSelectorTextContains('.alert', 'DKIM is disabled.');
    }

    public function testRecreateKey(): void
    {
        $this->loginClient($this->client);

        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $originalPrivateKey = $domain->getDkimPrivateKey();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($domain->getId()));
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Recreate Key');
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $updatedDomain = $domainRepository->find($domain->getId());
        static::assertInstanceOf(Domain::class, $updatedDomain);
        static::assertNotEquals($originalPrivateKey, $updatedDomain->getDkimPrivateKey());
        static::assertNotEmpty($updatedDomain->getDkimPrivateKey());
    }

    public function testRecreateKeyRejectsGet(): void
    {
        $this->loginClient($this->client);
        $domain = $this->findExampleDomain();
        $originalPrivateKey = $domain->getDkimPrivateKey();

        $this->client->request(Request::METHOD_GET, $this->getCrudUrl('recreateKey', $domain->getId()));

        static::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        static::assertSame($originalPrivateKey, $this->reloadPrivateKey($domain));
    }

    public function testRecreateKeyRejectsPostWithoutToken(): void
    {
        $this->loginClient($this->client);
        $domain = $this->findExampleDomain();
        $originalPrivateKey = $domain->getDkimPrivateKey();

        $this->client->request(Request::METHOD_POST, $this->getCrudUrl('recreateKey', $domain->getId()));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        static::assertSame($originalPrivateKey, $this->reloadPrivateKey($domain));
    }

    public function testRecreateKeyRejectsPostWithInvalidToken(): void
    {
        $this->loginClient($this->client);
        $domain = $this->findExampleDomain();
        $originalPrivateKey = $domain->getDkimPrivateKey();

        $this->client->request(Request::METHOD_POST, $this->getCrudUrl('recreateKey', $domain->getId()), ['token' => 'not-a-valid-token']);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        static::assertSame($originalPrivateKey, $this->reloadPrivateKey($domain));
    }

    public function testRecreateKeyAcceptsPostWithValidToken(): void
    {
        $this->loginClient($this->client);
        $domain = $this->findExampleDomain();
        $originalPrivateKey = $domain->getDkimPrivateKey();
        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), DKIMCrudController::RECREATE_KEY_CSRF_TOKEN_ID);

        $this->client->request(Request::METHOD_POST, $this->getCrudUrl('recreateKey', $domain->getId()), ['token' => $token]);

        static::assertResponseIsSuccessful();
        static::assertNotSame($originalPrivateKey, $this->reloadPrivateKey($domain));
    }

    public function testRecreateKeyAcceptsPostWithoutTokenWhenCsrfIsDisabled(): void
    {
        $this->withCsrfDisabled(function (): void {
            $domain = $this->findExampleDomain();
            $originalPrivateKey = $domain->getDkimPrivateKey();

            $this->client->request(Request::METHOD_POST, $this->getCrudUrl('recreateKey', $domain->getId()));

            static::assertResponseIsSuccessful();
            static::assertNotSame($originalPrivateKey, $this->reloadPrivateKey($domain));
        });
    }

    public function testRecreateKeyRejectsGetWhenCsrfIsDisabled(): void
    {
        $this->withCsrfDisabled(function (): void {
            $domain = $this->findExampleDomain();
            $originalPrivateKey = $domain->getDkimPrivateKey();

            $this->client->request(Request::METHOD_GET, $this->getCrudUrl('recreateKey', $domain->getId()));

            static::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
            static::assertSame($originalPrivateKey, $this->reloadPrivateKey($domain));
        });
    }

    public function testRecreateKeyRelativeFormUrl(): void
    {
        $this->loginClient($this->client);

        $domainRepository = $this->entityManager->getRepository(Domain::class);
        \assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($domain->getId()));
        static::assertResponseIsSuccessful();

        $form = $this->client->getCrawler()->filter('form.action-recreateKey');
        $action = $form->attr('action');

        static::assertStringStartsNotWith('http://', $action, 'Relative URL configuration is required for the DashboardController.');
        static::assertStringStartsNotWith('https://', $action, 'Relative URL configuration is required for the DashboardController.');
    }

    public function testCannotDeleteADomainThroughThisController(): void
    {
        $this->loginClient($this->client);
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        $domainId = (int) $domain->getId();
        $token = $this->issueCsrfToken($this->client, $this->generateIndexUrl(), 'ea-delete');

        $this->client->request(Request::METHOD_POST, $this->getCrudUrl(Action::DELETE, $domainId), ['token' => $token]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertInstanceOf(Domain::class, $this->entityManager->find(Domain::class, $domainId));
    }

    public function testCannotBatchDeleteADomainThroughThisController(): void
    {
        $this->loginClient($this->client);
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
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

    protected function getControllerFqcn(): string
    {
        return DKIMCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }

    private function withCsrfDisabled(callable $test): void
    {
        $previous = $_ENV['CSRF_ENABLED'] ?? null;
        $_ENV['CSRF_ENABLED'] = 'false';

        try {
            static::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->entityManager = static::getContainer()->get('doctrine')->getManager();
            $this->loginClient($this->client);

            $test();
        } finally {
            if (null === $previous) {
                unset($_ENV['CSRF_ENABLED']);
            } else {
                $_ENV['CSRF_ENABLED'] = $previous;
            }
        }
    }

    private function findExampleDomain(): Domain
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        return $domain;
    }

    private function reloadPrivateKey(Domain $domain): string
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Domain::class, $domain->getId());
        static::assertInstanceOf(Domain::class, $reloaded);

        return $reloaded->getDkimPrivateKey();
    }

    private function navigateToDomain(Domain $domain): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($domain->getId()));
        static::assertResponseIsSuccessful();

        return $crawler;
    }
}

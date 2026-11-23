<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\User;

use App\Entity\Domain;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\CsrfTokenTrait;
use Tests\Integration\Helper\UserTrait;

/**
 * A domain admin must not change what an admin fetches: a new host would receive the
 * password that stays when the edit form leaves it empty.
 */
class FetchmailAccountAdminOwnerTest extends WebTestCase
{
    use CsrfTokenTrait;
    use UserTrait;

    private const string ADMIN = 'admin@example.com';
    private const string DOMAIN_ADMIN = 'boss@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private int $adminAccountId;
    private int $plainAccountId;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $boss = $this->createUser($domain, 'boss');
        $boss->setDomainAdmin(true);
        $plain = $this->createUser($domain, 'plainuser');

        $adminAccount = $this->createAccount($this->findUser(self::ADMIN), 'admin.example.net');
        $plainAccount = $this->createAccount($plain, 'plain.example.net');
        $this->entityManager->flush();

        $this->adminAccountId = (int) $adminAccount->getId();
        $this->plainAccountId = (int) $plainAccount->getId();
    }

    public function testDomainAdminCannotOpenTheEditFormOfAnAdminsAccount(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/fetchmail-account/%d/edit', $this->adminAccountId));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDomainAdminCannotSubmitAnEditOfAnAdminsAccount(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_POST, \sprintf('/fetchmail-account/%d/edit', $this->adminAccountId), [
            'FetchmailAccount' => ['host' => 'attacker.example.net', 'port' => 143, 'protocol' => 'imap', 'username' => 'account'],
        ]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertSame('admin.example.net', $this->entityManager->find(FetchmailAccount::class, $this->adminAccountId)?->getHost());
    }

    public function testDomainAdminCannotDeleteAnAdminsAccount(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $token = $this->issueCsrfToken($this->client, '/fetchmail-account', 'ea-delete');
        $this->client->request(Request::METHOD_POST, \sprintf('/fetchmail-account/%d/delete', $this->adminAccountId), ['token' => $token]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertNotNull($this->entityManager->find(FetchmailAccount::class, $this->adminAccountId));
    }

    public function testDomainAdminCannotBatchDeleteAnAdminsAccount(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $token = $this->issueCsrfToken($this->client, '/fetchmail-account', \sprintf('ea-batch-action-%s-%s', Action::BATCH_DELETE, FetchmailAccount::class));
        $this->client->request(Request::METHOD_POST, '/fetchmail-account/batch-delete', [
            EA::BATCH_ACTION_NAME => Action::BATCH_DELETE,
            EA::BATCH_ACTION_ENTITY_IDS => [$this->plainAccountId, $this->adminAccountId],
            EA::ENTITY_FQCN => FetchmailAccount::class,
            EA::BATCH_ACTION_CSRF_TOKEN => $token,
        ]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        static::assertNotNull($this->entityManager->find(FetchmailAccount::class, $this->adminAccountId));
        static::assertNotNull($this->entityManager->find(FetchmailAccount::class, $this->plainAccountId));
    }

    public function testDomainAdminSeesAnAdminsAccountButCannotEditOrDeleteIt(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('tr[data-id="' . $this->adminAccountId . '"]'));
        static::assertCount(0, $crawler->filter(\sprintf('tr[data-id="%d"] a.action-edit', $this->adminAccountId)));
        static::assertCount(0, $crawler->filter(\sprintf('tr[data-id="%d"] a.action-delete', $this->adminAccountId)));
        static::assertCount(1, $crawler->filter(\sprintf('tr[data-id="%d"] a.action-edit', $this->plainAccountId)));
    }

    public function testAdminsAreNotOfferedAsOwnerToADomainAdmin(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account/new');

        static::assertResponseIsSuccessful();
        $labels = $crawler->filter('select[name="FetchmailAccount[user]"] option')->each(static fn ($option): string => $option->text());
        static::assertContains('plainuser@example.com', $labels);
        static::assertNotContains(self::ADMIN, $labels);
    }

    public function testAdminStillEditsTheirOwnAccount(): void
    {
        $this->loginClient($this->client, self::ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/fetchmail-account/%d/edit', $this->adminAccountId));

        static::assertResponseIsSuccessful();
    }

    private function createUser(Domain $domain, string $name): User
    {
        $user = new User();
        $user->setDomain($domain);
        $user->setName($name);
        $user->setPlainPassword('password123');
        $this->entityManager->persist($user);

        return $user;
    }

    private function createAccount(User $owner, string $host): FetchmailAccount
    {
        $account = new FetchmailAccount();
        $account->setUser($owner);
        $account->setHost($host);
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('account');
        $account->setPassword('secret-of-' . $host);
        $this->entityManager->persist($account);

        return $account;
    }

    private function findUser(string $emailAddress): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneByEmailAddress($emailAddress);
        static::assertInstanceOf(User::class, $user);

        return $user;
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Tool;

use App\Entity\FetchmailAccount;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\ManagementToolsTrait;

/**
 * A domain admin must not change what an admin fetches: a new host would receive the
 * password that is kept when it is left out.
 */
class FetchmailToolsAdminOwnerTest extends WebTestCase
{
    use ManagementToolsTrait;

    private const string SECRET = 'sentinel-fetchmail-secret';

    private int $adminAccountId;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();
        $this->seedManagementData();

        $account = $this->newAccount($this->findUser(self::ADMIN), 'admin-account');
        $this->entityManager()->flush();
        $this->adminAccountId = (int) $account->getId();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    public function testDomainAdminCannotUpdateAnAdminsAccount(): void
    {
        $result = $this->call(self::DOMAIN_ADMIN, 'fetchmail_update', ['id' => $this->adminAccountId, 'host' => 'attacker.example.net']);

        $this->assertToolDenied($result);
        self::assertSame($this->call(self::DOMAIN_ADMIN, 'fetchmail_update', ['id' => self::MISSING_ID, 'host' => 'attacker.example.net']), $result);

        $this->entityManager()->clear();
        $account = $this->entityManager()->find(FetchmailAccount::class, $this->adminAccountId);
        self::assertInstanceOf(FetchmailAccount::class, $account);
        self::assertSame('mail.elsewhere.example.net', $account->getHost());
        self::assertSame(self::SECRET, $account->getPassword());
    }

    public function testDomainAdminCannotDeleteAnAdminsAccount(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'fetchmail_delete', ['id' => $this->adminAccountId]));

        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(FetchmailAccount::class, $this->adminAccountId));
    }

    public function testDomainAdminCannotCreateAnAccountForAnAdmin(): void
    {
        $result = $this->call(self::DOMAIN_ADMIN, 'fetchmail_create', [
            'host' => 'attacker.example.net',
            'username' => 'injected',
            'password' => 'irrelevant',
            'owner' => self::ADMIN,
        ]);

        $this->assertToolDenied($result);
        self::assertNull($this->entityManager()->getRepository(FetchmailAccount::class)->findOneBy(['username' => 'injected']));
    }

    public function testDomainAdminCannotHandAnAccountOverToAnAdmin(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'fetchmail_update', ['id' => $this->peerAccountId, 'owner' => self::ADMIN]));

        $this->entityManager()->clear();
        $account = $this->entityManager()->find(FetchmailAccount::class, $this->peerAccountId);
        self::assertInstanceOf(FetchmailAccount::class, $account);
        self::assertInstanceOf(User::class, $account->getUser());
        self::assertSame(self::PEER, $account->getUser()->getUserIdentifier());
    }

    public function testDomainAdminCanStillListAndReadAnAdminsAccountWithoutItsPassword(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_list'));
        self::assertContains(self::ADMIN, $this->pageColumn($page, 'owner'));

        $account = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_get', ['id' => $this->adminAccountId]));
        self::assertSame(self::ADMIN, $account['owner']);
        self::assertStringNotContainsString(self::SECRET, json_encode($account, \JSON_THROW_ON_ERROR));
    }

    public function testAdminStillUpdatesAndDeletesTheirOwnAccount(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::ADMIN, 'fetchmail_update', ['id' => $this->adminAccountId, 'host' => 'new.example.net']));
        self::assertSame('new.example.net', $updated['host']);

        $this->assertToolSucceeded($this->call(self::ADMIN, 'fetchmail_delete', ['id' => $this->adminAccountId]));
        self::assertNull($this->entityManager()->getRepository(FetchmailAccount::class)->find($this->adminAccountId));
    }
}

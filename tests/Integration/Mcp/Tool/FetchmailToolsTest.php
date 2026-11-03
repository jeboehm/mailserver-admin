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
use App\Repository\FetchmailAccountRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\ManagementToolsTrait;

class FetchmailToolsTest extends WebTestCase
{
    use ManagementToolsTrait;

    private const string SECRET = 'sentinel-fetchmail-secret';
    private const string NEW_SECRET = 'another-sentinel-secret';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();
        $this->seedManagementData();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    public function testAdminListsAllAccounts(): void
    {
        self::assertSame(3, $this->assertToolSucceeded($this->call(self::ADMIN, 'fetchmail_list'))['total']);
    }

    public function testDomainAdminListsTheAccountsOfTheirDomain(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_list'));

        self::assertSame(2, $page['total']);
        self::assertSame([self::PEER, self::REGULAR_USER], $this->pageColumn($page, 'owner'));
    }

    public function testRegularUserListsOnlyTheirOwnAccounts(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::REGULAR_USER, 'fetchmail_list'));

        self::assertSame(1, $page['total']);
        self::assertSame([self::REGULAR_USER], $this->pageColumn($page, 'owner'));
    }

    public function testListIsPaginatedAndNeverReturnsPasswords(): void
    {
        $result = $this->call(self::ADMIN, 'fetchmail_list', ['limit' => 1, 'offset' => 1]);

        $page = $this->assertToolSucceeded($result);
        self::assertSame(3, $page['total']);
        self::assertCount(1, $page['items']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($result));
    }

    public function testGetReturnsAnAccountWithoutItsPassword(): void
    {
        $result = $this->call(self::REGULAR_USER, 'fetchmail_get', ['id' => $this->regularAccountId]);

        self::assertSame([
            'id' => $this->regularAccountId,
            'owner' => self::REGULAR_USER,
            'host' => 'mail.elsewhere.example.net',
            'protocol' => 'imap',
            'port' => 143,
            'username' => 'regular',
            'ssl' => false,
            'verify_ssl' => true,
            'last_run' => null,
            'last_run_succeeded' => null,
        ], $this->assertToolSucceeded($result));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($result));
    }

    public function testDomainAdminGetsAnAccountOfTheirDomain(): void
    {
        self::assertSame(self::PEER, $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_get', ['id' => $this->peerAccountId]))['owner']);
    }

    public function testAdminGetsAnAccountOfAnyDomain(): void
    {
        self::assertSame(self::FOREIGN_USER, $this->assertToolSucceeded($this->call(self::ADMIN, 'fetchmail_get', ['id' => $this->foreignAccountId]))['owner']);
    }

    public function testRegularUserCannotGetSomebodyElsesAccount(): void
    {
        $peer = $this->call(self::REGULAR_USER, 'fetchmail_get', ['id' => $this->peerAccountId]);
        $foreign = $this->call(self::REGULAR_USER, 'fetchmail_get', ['id' => $this->foreignAccountId]);
        $missing = $this->call(self::REGULAR_USER, 'fetchmail_get', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($peer);
        self::assertSame($missing, $peer);
        self::assertSame($missing, $foreign);
    }

    public function testDomainAdminCannotGetAForeignAccount(): void
    {
        $foreign = $this->call(self::DOMAIN_ADMIN, 'fetchmail_get', ['id' => $this->foreignAccountId]);
        $missing = $this->call(self::DOMAIN_ADMIN, 'fetchmail_get', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);
    }

    public function testRegularUserCreatesAnAccountForThemselves(): void
    {
        $result = $this->call(self::REGULAR_USER, 'fetchmail_create', $this->newAccountArguments());

        $created = $this->assertToolSucceeded($result);
        self::assertSame(self::REGULAR_USER, $created['owner']);
        self::assertSame(993, $created['port']);
        self::assertTrue($created['ssl']);
        self::assertStringNotContainsString(self::NEW_SECRET, (string) json_encode($result));
        self::assertSame(self::NEW_SECRET, $this->account($created['id'])->getPassword());
    }

    public function testRegularUserCannotCreateForSomebodyElse(): void
    {
        $this->assertToolDenied($this->call(self::REGULAR_USER, 'fetchmail_create', [...$this->newAccountArguments(), 'owner' => self::PEER]));

        self::assertNull($this->repository()->findOneBy(['username' => 'fresh']));
    }

    public function testDomainAdminAssignsAnAccountWithinTheirDomain(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_create', [...$this->newAccountArguments(), 'owner' => self::PEER]));

        self::assertSame(self::PEER, $created['owner']);
    }

    public function testDomainAdminCannotAssignOutsideTheirDomain(): void
    {
        $foreign = $this->call(self::DOMAIN_ADMIN, 'fetchmail_create', [...$this->newAccountArguments(), 'owner' => self::FOREIGN_USER]);
        $unknown = $this->call(self::DOMAIN_ADMIN, 'fetchmail_create', [...$this->newAccountArguments(), 'owner' => 'nobody@example.com']);

        $this->assertToolDenied($foreign);
        self::assertSame($unknown, $foreign);
        self::assertNull($this->repository()->findOneBy(['username' => 'fresh']));
    }

    public function testAdminAssignsToAnyone(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'fetchmail_create', [...$this->newAccountArguments(), 'owner' => self::FOREIGN_USER]));

        self::assertSame(self::FOREIGN_USER, $created['owner']);
    }

    public function testCreateValidatesLikeTheFormWithoutEchoingThePassword(): void
    {
        $result = $this->call(self::REGULAR_USER, 'fetchmail_create', [...$this->newAccountArguments(), 'host' => 'not a host', 'protocol' => 'smoke', 'port' => 0]);

        $this->assertToolRejectedField($result, 'host');
        $this->assertToolRejectedField($result, 'protocol');
        $this->assertToolRejectedField($result, 'port');
        self::assertStringNotContainsString(self::NEW_SECRET, (string) json_encode($result));
    }

    public function testCreateRejectsAnEmptyPassword(): void
    {
        $this->assertToolRejectedField($this->call(self::REGULAR_USER, 'fetchmail_create', [...$this->newAccountArguments(), 'password' => '']), 'password');
    }

    public function testUpdateChangesOnlyWhatIsPassedAndKeepsThePassword(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::REGULAR_USER, 'fetchmail_update', ['id' => $this->regularAccountId, 'port' => 110, 'protocol' => 'pop3']));

        self::assertSame(110, $updated['port']);
        self::assertSame('pop3', $updated['protocol']);
        self::assertSame('regular', $updated['username']);
        self::assertSame(self::SECRET, $this->account($this->regularAccountId)->getPassword());
    }

    public function testUpdateReplacesThePasswordWithoutEchoingIt(): void
    {
        $result = $this->call(self::REGULAR_USER, 'fetchmail_update', ['id' => $this->regularAccountId, 'password' => self::NEW_SECRET]);

        $this->assertToolSucceeded($result);
        self::assertStringNotContainsString(self::NEW_SECRET, (string) json_encode($result));
        self::assertSame(self::NEW_SECRET, $this->account($this->regularAccountId)->getPassword());
    }

    public function testUpdateRejectsAnEmptyPasswordAndKeepsTheOldOne(): void
    {
        $this->assertToolRejectedField($this->call(self::REGULAR_USER, 'fetchmail_update', ['id' => $this->regularAccountId, 'password' => '']), 'password');

        $this->entityManager()->clear();
        self::assertSame(self::SECRET, $this->account($this->regularAccountId)->getPassword());
    }

    public function testDomainAdminReassignsWithinTheirDomain(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_update', ['id' => $this->peerAccountId, 'owner' => self::REGULAR_USER]));

        self::assertSame(self::REGULAR_USER, $updated['owner']);
    }

    public function testDomainAdminCannotReassignOutsideTheirDomain(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'fetchmail_update', ['id' => $this->peerAccountId, 'owner' => self::FOREIGN_USER, 'port' => 110]));

        $this->entityManager()->clear();
        $account = $this->account($this->peerAccountId);
        self::assertSame(self::PEER, $account->getUser()?->getUserIdentifier());
        self::assertSame(143, $account->getPort());
    }

    public function testRegularUserCannotGiveTheirAccountAway(): void
    {
        $this->assertToolDenied($this->call(self::REGULAR_USER, 'fetchmail_update', ['id' => $this->regularAccountId, 'owner' => self::PEER]));
    }

    public function testRegularUserCannotUpdateSomebodyElsesAccount(): void
    {
        $peer = $this->call(self::REGULAR_USER, 'fetchmail_update', ['id' => $this->peerAccountId, 'port' => 110]);
        $missing = $this->call(self::REGULAR_USER, 'fetchmail_update', ['id' => self::MISSING_ID, 'port' => 110]);

        $this->assertToolDenied($peer);
        self::assertSame($missing, $peer);

        $this->entityManager()->clear();
        self::assertSame(143, $this->account($this->peerAccountId)->getPort());
    }

    public function testDomainAdminCannotUpdateAForeignAccount(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'fetchmail_update', ['id' => $this->foreignAccountId, 'port' => 110]));
    }

    public function testRegularUserDeletesOwnAccount(): void
    {
        self::assertSame(['deleted' => true], $this->assertToolSucceeded($this->call(self::REGULAR_USER, 'fetchmail_delete', ['id' => $this->regularAccountId])));
        self::assertNull($this->repository()->find($this->regularAccountId));
    }

    public function testDomainAdminDeletesAnAccountOfTheirDomain(): void
    {
        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'fetchmail_delete', ['id' => $this->peerAccountId]));

        self::assertNull($this->repository()->find($this->peerAccountId));
    }

    public function testAdminDeletesAnyAccount(): void
    {
        $this->assertToolSucceeded($this->call(self::ADMIN, 'fetchmail_delete', ['id' => $this->foreignAccountId]));

        self::assertNull($this->repository()->find($this->foreignAccountId));
    }

    public function testDeletingSomebodyElsesAccountAnswersLikeAMissingOne(): void
    {
        $peer = $this->call(self::REGULAR_USER, 'fetchmail_delete', ['id' => $this->peerAccountId]);
        $foreign = $this->call(self::DOMAIN_ADMIN, 'fetchmail_delete', ['id' => $this->foreignAccountId]);
        $missing = $this->call(self::REGULAR_USER, 'fetchmail_delete', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($peer);
        self::assertSame($missing, $peer);
        self::assertSame($missing, $foreign);
        self::assertInstanceOf(FetchmailAccount::class, $this->repository()->find($this->peerAccountId));
        self::assertInstanceOf(FetchmailAccount::class, $this->repository()->find($this->foreignAccountId));
    }

    /**
     * @return array<string, mixed>
     */
    private function newAccountArguments(): array
    {
        return [
            'host' => 'imap.elsewhere.example.net',
            'username' => 'fresh',
            'password' => self::NEW_SECRET,
            'port' => 993,
            'ssl' => true,
        ];
    }

    private function account(int $id): FetchmailAccount
    {
        $account = $this->repository()->find($id);
        self::assertInstanceOf(FetchmailAccount::class, $account);

        return $account;
    }

    private function repository(): FetchmailAccountRepository
    {
        return static::getContainer()->get(FetchmailAccountRepository::class);
    }
}

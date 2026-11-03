<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\CsrfTokenTrait;
use Tests\Integration\Helper\UserTrait;

/**
 * Pins what each role can see and do through the web interface, per rule of the
 * access rule table in issue #549.
 */
class AccessRulesTest extends WebTestCase
{
    use CsrfTokenTrait;
    use UserTrait;

    private const string ADMIN = 'admin@example.com';
    private const string DOMAIN_ADMIN = 'boss@example.com';
    private const string REGULAR_USER = 'plainuser@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private int $foreignUserId;
    private int $plainUserId;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $ownDomain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $ownDomain);

        $foreignDomain = new Domain();
        $foreignDomain->setName('foreign.invalid');
        $this->entityManager->persist($foreignDomain);

        $emptyDomain = new Domain();
        $emptyDomain->setName('empty.invalid');
        $this->entityManager->persist($emptyDomain);

        $boss = $this->createUser($ownDomain, 'boss');
        $boss->setDomainAdmin(true);
        $plain = $this->createUser($ownDomain, 'plainuser');
        $neighbour = $this->createUser($ownDomain, 'neighbour');
        $foreign = $this->createUser($foreignDomain, 'foreignuser');

        $this->createAlias($ownDomain, 'ownalias');
        $this->createAlias($foreignDomain, 'foreignalias');

        $this->createFetchmailAccount($plain, 'plain.example.net');
        $this->createFetchmailAccount($neighbour, 'neighbour.example.net');
        $this->createFetchmailAccount($foreign, 'foreign.example.net');

        $this->entityManager->flush();

        $this->plainUserId = (int) $plain->getId();
        $this->foreignUserId = (int) $foreign->getId();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenPages(): iterable
    {
        yield 'domain admin on domains' => [self::DOMAIN_ADMIN, '/domain'];
        yield 'domain admin on DKIM' => [self::DOMAIN_ADMIN, '/dkim'];
        yield 'regular user on domains' => [self::REGULAR_USER, '/domain'];
        yield 'regular user on DKIM' => [self::REGULAR_USER, '/dkim'];
        yield 'regular user on users' => [self::REGULAR_USER, '/user'];
        yield 'regular user on aliases' => [self::REGULAR_USER, '/alias'];
        yield 'regular user on DNS wizard' => [self::REGULAR_USER, '/dns-wizard/'];
    }

    #[DataProvider('forbiddenPages')]
    public function testRoleMatrixForbidsPage(string $login, string $path): void
    {
        $this->loginClient($this->client, $login);

        $this->client->request(Request::METHOD_GET, $path);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminSeesUsersAndAliasesOfAllDomains(): void
    {
        $this->loginClient($this->client, self::ADMIN);

        $this->client->request(Request::METHOD_GET, '/user');
        static::assertResponseIsSuccessful();
        static::assertAnySelectorTextContains('body', 'plainuser');
        static::assertAnySelectorTextContains('body', 'foreignuser');

        $this->client->request(Request::METHOD_GET, '/alias');
        static::assertAnySelectorTextContains('body', 'ownalias');
        static::assertAnySelectorTextContains('body', 'foreignalias');
    }

    public function testDomainAdminSeesOnlyUsersOfTheirOwnDomain(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);

        $this->client->request(Request::METHOD_GET, '/user');

        static::assertResponseIsSuccessful();
        $this->assertResponseContains('plainuser');
        $this->assertResponseNotContains('foreignuser');
    }

    public function testDomainAdminSeesOnlyAliasesOfTheirOwnDomain(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);

        $this->client->request(Request::METHOD_GET, '/alias');

        static::assertResponseIsSuccessful();
        $this->assertResponseContains('ownalias');
        $this->assertResponseNotContains('foreignalias');
    }

    public function testDomainAdminCannotOpenAUserOfAnotherDomain(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);

        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $this->foreignUserId));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOnlyAdminsGetTheAdminAndDomainFieldsOnTheUserForm(): void
    {
        $this->loginClient($this->client, self::ADMIN);
        $form = $this->client->request(Request::METHOD_GET, '/user/new')->selectButton('Create')->form();
        static::assertTrue($form->has('User[domain]'));
        static::assertTrue($form->has('User[admin]'));

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $form = $this->client->request(Request::METHOD_GET, '/user/new')->selectButton('Create')->form();
        static::assertFalse($form->has('User[domain]'));
        static::assertFalse($form->has('User[admin]'));
        static::assertTrue($form->has('User[domainAdmin]'));
    }

    public function testOnlyAdminsGetTheDomainFieldOnTheAliasForm(): void
    {
        $this->loginClient($this->client, self::ADMIN);
        $form = $this->client->request(Request::METHOD_GET, '/alias/new')->selectButton('Create')->form();
        static::assertTrue($form->has('Alias[domain]'));

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $form = $this->client->request(Request::METHOD_GET, '/alias/new')->selectButton('Create')->form();
        static::assertFalse($form->has('Alias[domain]'));
    }

    public function testDomainAdminCannotSmuggleAdminFlagOrDomainIntoANewUser(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $crawler = $this->client->request(Request::METHOD_GET, '/user/new');
        $values = $crawler->selectButton('Create')->form()->getPhpValues();
        $values['User']['name'] = 'smuggled';
        $values['User']['plainPassword'] = ['first' => 'password123', 'second' => 'password123'];
        $values['User']['quota'] = '0';
        $values['User']['admin'] = '1';
        $values['User']['domain'] = '999';

        $this->client->request(Request::METHOD_POST, '/user/new', $values);

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['name' => 'smuggled']);
        static::assertInstanceOf(User::class, $user);
        static::assertFalse($user->isAdmin());
        static::assertSame('example.com', $user->getDomain()?->getName());
    }

    public function testNewUserOfADomainAdminLandsInTheirDomain(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, '/user/new');

        $this->client->submitForm('Create', [
            'User[name]' => 'freshuser',
            'User[plainPassword][first]' => 'password123',
            'User[plainPassword][second]' => 'password123',
            'User[quota]' => 0,
        ]);

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['name' => 'freshuser']);
        static::assertInstanceOf(User::class, $user);
        static::assertSame('example.com', $user->getDomain()?->getName());
        static::assertFalse($user->isAdmin());
    }

    public function testNewAliasOfADomainAdminLandsInTheirDomain(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, '/alias/new');

        $this->client->submitForm('Create', [
            'Alias[name]' => 'freshalias',
            'Alias[destination]' => 'plainuser@example.com',
        ]);

        $this->entityManager->clear();
        $alias = $this->entityManager->getRepository(Alias::class)->findOneBy(['name' => 'freshalias']);
        static::assertInstanceOf(Alias::class, $alias);
        static::assertSame('example.com', $alias->getDomain()?->getName());
    }

    public function testNewUserFormOfAnAdminPreselectsTheirDomain(): void
    {
        $this->loginClient($this->client, self::ADMIN);
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $crawler = $this->client->request(Request::METHOD_GET, '/user/new');

        static::assertSame((string) $domain->getId(), $crawler->selectButton('Create')->form()->get('User[domain]')->getValue());
    }

    public function testDomainAdminCannotDropOwnRoleOrDisableThemselves(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $boss = $this->findUser(self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $boss->getId()));

        $form = $this->client->getCrawler()->selectButton('Save changes')->form();
        $form->setValues(['User[domainAdmin]' => false]);
        $form->setValues(['User[enabled]' => false]);
        $form['User[quota]'] = '77';
        $this->client->submit($form);

        $this->entityManager->clear();
        $boss = $this->findUser(self::DOMAIN_ADMIN);
        static::assertSame(77, $boss->getQuota());
        static::assertTrue($boss->isDomainAdmin());
        static::assertFalse($boss->isAdmin());
        static::assertTrue($boss->getEnabled());
    }

    public function testAdminCannotDropOwnRoleOrDisableThemselves(): void
    {
        $this->loginClient($this->client, self::ADMIN);
        $admin = $this->findUser(self::ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $admin->getId()));

        $form = $this->client->getCrawler()->selectButton('Save changes')->form();
        $form->setValues(['User[admin]' => false]);
        $form->setValues(['User[enabled]' => false]);
        $form['User[quota]'] = '88';
        $this->client->submit($form);

        $this->entityManager->clear();
        $admin = $this->findUser(self::ADMIN);
        static::assertSame(88, $admin->getQuota());
        static::assertTrue($admin->isAdmin());
        static::assertFalse($admin->isDomainAdmin());
        static::assertTrue($admin->getEnabled());
    }

    public function testAdminCanStillDisableSomeoneElse(): void
    {
        $this->loginClient($this->client, self::ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $this->plainUserId));

        $form = $this->client->getCrawler()->selectButton('Save changes')->form();
        $form->setValues(['User[enabled]' => false]);
        $form->setValues(['User[domainAdmin]' => true]);
        $this->client->submit($form);

        $this->entityManager->clear();
        $plain = $this->findUser(self::REGULAR_USER);
        static::assertFalse($plain->getEnabled());
        static::assertTrue($plain->isDomainAdmin());
    }

    public function testAdminSeesAllFetchmailAccountsAndOwners(): void
    {
        $this->loginClient($this->client, self::ADMIN);

        $this->client->request(Request::METHOD_GET, '/fetchmail-account');
        $this->assertResponseContains('plain.example.net');
        $this->assertResponseContains('foreign.example.net');

        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account/new');
        static::assertCount(1, $crawler->filter(\sprintf('select[name="FetchmailAccount[user]"] option[value="%d"]', $this->foreignUserId)));
    }

    public function testDomainAdminSeesFetchmailAccountsOfTheirDomainOnly(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);

        $this->client->request(Request::METHOD_GET, '/fetchmail-account');

        static::assertResponseIsSuccessful();
        $this->assertResponseContains('plain.example.net');
        $this->assertResponseContains('neighbour.example.net');
        $this->assertResponseNotContains('foreign.example.net');
    }

    public function testDomainAdminCanOnlyChooseFetchmailOwnersInTheirDomain(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);

        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account/new');

        $select = 'select[name="FetchmailAccount[user]"] option[value="%d"]';
        static::assertCount(1, $crawler->filter(\sprintf($select, $this->plainUserId)));
        static::assertCount(0, $crawler->filter(\sprintf($select, $this->foreignUserId)));
    }

    public function testDomainAdminCannotAssignAFetchmailAccountToAForeignUser(): void
    {
        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account/new');
        $form = $crawler->selectButton('Create')->form();
        $form->disableValidation();
        $form['FetchmailAccount[user]'] = (string) $this->foreignUserId;
        $form['FetchmailAccount[host]'] = 'smuggled.example.net';
        $form['FetchmailAccount[port]'] = '143';
        $form['FetchmailAccount[protocol]'] = 'imap';
        $form['FetchmailAccount[username]'] = 'smuggled';
        $form['FetchmailAccount[password]'] = 'password';

        $this->client->submit($form);

        $this->entityManager->clear();
        static::assertNull($this->entityManager->getRepository(FetchmailAccount::class)->findOneBy(['host' => 'smuggled.example.net']));
    }

    public function testRegularUserSeesOnlyTheirOwnFetchmailAccounts(): void
    {
        $this->loginClient($this->client, self::REGULAR_USER);

        $this->client->request(Request::METHOD_GET, '/fetchmail-account');

        static::assertResponseIsSuccessful();
        $this->assertResponseContains('plain.example.net');
        $this->assertResponseNotContains('neighbour.example.net');
        $this->assertResponseNotContains('foreign.example.net');
    }

    public function testNewFetchmailAccountOfARegularUserBelongsToThem(): void
    {
        $this->loginClient($this->client, self::REGULAR_USER);
        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account/new');
        $form = $crawler->selectButton('Create')->form();
        static::assertFalse($form->has('FetchmailAccount[user]'));

        $this->client->submit($form, [
            'FetchmailAccount[host]' => 'fresh.example.net',
            'FetchmailAccount[port]' => '143',
            'FetchmailAccount[protocol]' => 'imap',
            'FetchmailAccount[username]' => 'fresh',
            'FetchmailAccount[password]' => 'password',
        ]);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(FetchmailAccount::class)->findOneBy(['host' => 'fresh.example.net']);
        static::assertInstanceOf(FetchmailAccount::class, $account);
        static::assertSame($this->plainUserId, $account->getUser()?->getId());
    }

    public function testRegularUserCannotSmuggleAnOwnerIntoANewFetchmailAccount(): void
    {
        $this->loginClient($this->client, self::REGULAR_USER);
        $crawler = $this->client->request(Request::METHOD_GET, '/fetchmail-account/new');
        $values = $crawler->selectButton('Create')->form()->getPhpValues();
        $values['FetchmailAccount'] = array_merge($values['FetchmailAccount'], [
            'user' => (string) $this->foreignUserId,
            'host' => 'smuggled.example.net',
            'port' => '143',
            'protocol' => 'imap',
            'username' => 'smuggled',
            'password' => 'password',
        ]);

        $this->client->request(Request::METHOD_POST, '/fetchmail-account/new', $values);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(FetchmailAccount::class)->findOneBy(['host' => 'smuggled.example.net']);
        static::assertInstanceOf(FetchmailAccount::class, $account);
        static::assertSame($this->plainUserId, $account->getUser()?->getId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonAdmins(): iterable
    {
        yield 'domain admin' => [self::DOMAIN_ADMIN];
        yield 'regular user' => [self::REGULAR_USER];
    }

    #[DataProvider('nonAdmins')]
    public function testOnlyAdminsMayRecreateADkimKey(string $login): void
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        $key = $domain->getDkimPrivateKey();

        $this->loginClient($this->client, $login);
        $this->client->request(Request::METHOD_POST, \sprintf('/dkim/recreate/%d', $domain->getId()));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);
        static::assertSame($key, $domain->getDkimPrivateKey());
    }

    /**
     * @return iterable<string, array{string, string, class-string, string}>
     */
    public static function forbiddenDeletions(): iterable
    {
        yield 'domain admin, alias of another domain' => [self::DOMAIN_ADMIN, '/alias', Alias::class, 'foreignalias'];
        yield 'domain admin, user of another domain' => [self::DOMAIN_ADMIN, '/user', User::class, 'foreignuser'];
        yield 'domain admin, themselves' => [self::DOMAIN_ADMIN, '/user', User::class, 'boss'];
        yield 'domain admin, admin of their domain' => [self::DOMAIN_ADMIN, '/user', User::class, 'admin'];
        yield 'domain admin, fetchmail account of another domain' => [self::DOMAIN_ADMIN, '/fetchmail-account', FetchmailAccount::class, 'foreign.example.net'];
        yield 'domain admin, domain' => [self::DOMAIN_ADMIN, '/domain', Domain::class, 'empty.invalid'];
        yield 'domain admin, domain through DKIM' => [self::DOMAIN_ADMIN, '/dkim', Domain::class, 'empty.invalid'];
        yield 'regular user, fetchmail account of a neighbour' => [self::REGULAR_USER, '/fetchmail-account', FetchmailAccount::class, 'neighbour.example.net'];
        yield 'regular user, fetchmail account of another domain' => [self::REGULAR_USER, '/fetchmail-account', FetchmailAccount::class, 'foreign.example.net'];
        yield 'regular user, alias' => [self::REGULAR_USER, '/alias', Alias::class, 'ownalias'];
        yield 'regular user, user' => [self::REGULAR_USER, '/user', User::class, 'neighbour'];
        yield 'regular user, domain' => [self::REGULAR_USER, '/domain', Domain::class, 'empty.invalid'];
        yield 'regular user, domain through DKIM' => [self::REGULAR_USER, '/dkim', Domain::class, 'empty.invalid'];
        yield 'admin, domain through DKIM' => [self::ADMIN, '/dkim', Domain::class, 'empty.invalid'];
        yield 'admin, themselves' => [self::ADMIN, '/user', User::class, 'admin'];
        yield 'admin, domain with users' => [self::ADMIN, '/domain', Domain::class, 'foreign.invalid'];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('forbiddenDeletions')]
    public function testBatchDeleteIsRejected(string $login, string $path, string $entity, string $name): void
    {
        $id = $this->idOf($entity, $name);

        $this->loginClient($this->client, $login);
        $this->batchDelete($path, $entity, [$id]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertExists($entity, $id);
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('forbiddenDeletions')]
    public function testSingleDeleteIsRejected(string $login, string $path, string $entity, string $name): void
    {
        $id = $this->idOf($entity, $name);

        $this->loginClient($this->client, $login);
        $this->singleDelete($path, $id);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertExists($entity, $id);
    }

    /**
     * Each batch holds an entry the actor may delete, followed by one they may not.
     *
     * @return iterable<string, array{string, string, class-string, string, string}>
     */
    public static function mixedBatches(): iterable
    {
        yield 'domain admin, aliases' => [self::DOMAIN_ADMIN, '/alias', Alias::class, 'ownalias', 'foreignalias'];
        yield 'domain admin, users' => [self::DOMAIN_ADMIN, '/user', User::class, 'plainuser', 'foreignuser'];
        yield 'domain admin, users including themselves' => [self::DOMAIN_ADMIN, '/user', User::class, 'plainuser', 'boss'];
        yield 'domain admin, users including an admin' => [self::DOMAIN_ADMIN, '/user', User::class, 'plainuser', 'admin'];
        yield 'domain admin, fetchmail accounts' => [self::DOMAIN_ADMIN, '/fetchmail-account', FetchmailAccount::class, 'plain.example.net', 'foreign.example.net'];
        yield 'regular user, fetchmail accounts' => [self::REGULAR_USER, '/fetchmail-account', FetchmailAccount::class, 'plain.example.net', 'neighbour.example.net'];
        yield 'admin, users including themselves' => [self::ADMIN, '/user', User::class, 'plainuser', 'admin'];
        yield 'admin, domains' => [self::ADMIN, '/domain', Domain::class, 'empty.invalid', 'foreign.invalid'];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('mixedBatches')]
    public function testRejectedBatchDeleteRemovesNothing(string $login, string $path, string $entity, string $permitted, string $forbidden): void
    {
        $permittedId = $this->idOf($entity, $permitted);
        $forbiddenId = $this->idOf($entity, $forbidden);

        $this->loginClient($this->client, $login);
        $this->batchDelete($path, $entity, [$permittedId, $forbiddenId]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertExists($entity, $permittedId);
        $this->assertExists($entity, $forbiddenId);
    }

    /**
     * @return iterable<string, array{string, string, class-string, string}>
     */
    public static function permittedDeletions(): iterable
    {
        yield 'admin, alias of any domain' => [self::ADMIN, '/alias', Alias::class, 'foreignalias'];
        yield 'admin, fetchmail account of any domain' => [self::ADMIN, '/fetchmail-account', FetchmailAccount::class, 'foreign.example.net'];
        yield 'domain admin, alias of their domain' => [self::DOMAIN_ADMIN, '/alias', Alias::class, 'ownalias'];
        yield 'domain admin, fetchmail account in their domain' => [self::DOMAIN_ADMIN, '/fetchmail-account', FetchmailAccount::class, 'neighbour.example.net'];
        yield 'regular user, own fetchmail account' => [self::REGULAR_USER, '/fetchmail-account', FetchmailAccount::class, 'plain.example.net'];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('permittedDeletions')]
    public function testPermittedBatchDeleteRemovesTheEntry(string $login, string $path, string $entity, string $name): void
    {
        $id = $this->idOf($entity, $name);

        $this->loginClient($this->client, $login);
        $this->batchDelete($path, $entity, [$id]);

        static::assertResponseIsSuccessful();
        $this->assertGone($entity, $id);
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('permittedDeletions')]
    public function testPermittedSingleDeleteRemovesTheEntry(string $login, string $path, string $entity, string $name): void
    {
        $id = $this->idOf($entity, $name);

        $this->loginClient($this->client, $login);
        $this->singleDelete($path, $id);

        static::assertResponseIsSuccessful();
        $this->assertGone($entity, $id);
    }

    public function testDomainAdminCannotOpenTheEditFormOfAnAdmin(): void
    {
        $id = $this->idOf(User::class, 'admin');

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $id));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDomainAdminCannotSubmitTheEditFormOfAnAdmin(): void
    {
        $id = $this->idOf(User::class, 'admin');
        $password = $this->storedAdmin()->getPassword();

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_POST, \sprintf('/user/%d/edit', $id), ['User' => [
            'enabled' => '',
            'plainPassword' => ['first' => 'Correct-Horse-Battery-5taple!', 'second' => 'Correct-Horse-Battery-5taple!'],
        ]]);

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $admin = $this->storedAdmin();
        static::assertSame($password, $admin->getPassword());
        static::assertTrue($admin->getEnabled());
    }

    public function testDomainAdminCannotToggleAnAdmin(): void
    {
        $id = $this->idOf(User::class, 'admin');

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->xmlHttpRequest(Request::METHOD_PATCH, \sprintf('/user/%d/edit?fieldName=enabled&newValue=false', $id));

        static::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        static::assertTrue($this->storedAdmin()->getEnabled());
    }

    public function testDomainAdminSeesAnAdminInTheListButIsOfferedNoChange(): void
    {
        $adminId = $this->idOf(User::class, 'admin');

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, '/user');

        static::assertResponseIsSuccessful();
        $this->assertResponseContains(\sprintf('data-id="%d"', $adminId));
        $this->assertResponseContains(\sprintf('/user/%d/edit', $this->plainUserId));
        $this->assertResponseContains(\sprintf('/user/%d/delete', $this->plainUserId));
        $this->assertResponseNotContains(\sprintf('/user/%d/edit', $adminId));
        $this->assertResponseNotContains(\sprintf('/user/%d/delete', $adminId));
    }

    public function testDomainAdminCanReadAnAdminButIsOfferedNoChange(): void
    {
        $adminId = $this->idOf(User::class, 'admin');

        $this->loginClient($this->client, self::DOMAIN_ADMIN);
        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d', $adminId));

        static::assertResponseIsSuccessful();
        $this->assertResponseNotContains(\sprintf('/user/%d/edit', $adminId));
        $this->assertResponseNotContains(\sprintf('/user/%d/delete', $adminId));
    }

    public function testDomainAdminStillEditsThemselvesAndOtherUsers(): void
    {
        $bossId = $this->idOf(User::class, 'boss');

        $this->loginClient($this->client, self::DOMAIN_ADMIN);

        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $bossId));
        static::assertResponseIsSuccessful();

        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $this->plainUserId));
        static::assertResponseIsSuccessful();
    }

    public function testAdminStillEditsAndDeletesAnotherAdmin(): void
    {
        $plain = $this->entityManager->find(User::class, $this->plainUserId);
        static::assertInstanceOf(User::class, $plain);
        $plain->setAdmin(true);
        $this->entityManager->flush();

        $this->loginClient($this->client, self::ADMIN);

        $this->client->request(Request::METHOD_GET, \sprintf('/user/%d/edit', $this->plainUserId));
        static::assertResponseIsSuccessful();

        $this->client->request(Request::METHOD_GET, '/user');
        $this->assertResponseContains(\sprintf('/user/%d/delete', $this->plainUserId));
    }

    private function storedAdmin(): User
    {
        $this->entityManager->clear();
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['name' => 'admin']);
        static::assertInstanceOf(User::class, $admin);

        return $admin;
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

    private function createAlias(Domain $domain, string $name): void
    {
        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName($name);
        $alias->setDestination('target@example.com');
        $this->entityManager->persist($alias);
    }

    private function createFetchmailAccount(User $owner, string $host): void
    {
        $account = new FetchmailAccount();
        $account->setUser($owner);
        $account->setHost($host);
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('account');
        $account->setPassword('password');
        $this->entityManager->persist($account);
    }

    /**
     * @param class-string $entity
     * @param list<int>    $ids
     */
    private function batchDelete(string $path, string $entity, array $ids): void
    {
        $token = $this->issueCsrfToken($this->client, '/fetchmail-account', \sprintf('ea-batch-action-%s-%s', Action::BATCH_DELETE, $entity));

        $this->client->request(Request::METHOD_POST, $path . '/batch-delete', [
            EA::BATCH_ACTION_NAME => Action::BATCH_DELETE,
            EA::BATCH_ACTION_ENTITY_IDS => $ids,
            EA::ENTITY_FQCN => $entity,
            EA::BATCH_ACTION_CSRF_TOKEN => $token,
        ]);
    }

    private function singleDelete(string $path, int $id): void
    {
        $token = $this->issueCsrfToken($this->client, '/fetchmail-account', 'ea-delete');

        $this->client->request(Request::METHOD_POST, \sprintf('%s/%d/delete', $path, $id), ['token' => $token]);
    }

    /**
     * @param class-string $entity
     */
    private function idOf(string $entity, string $name): int
    {
        $found = $this->entityManager->getRepository($entity)->findOneBy([FetchmailAccount::class === $entity ? 'host' : 'name' => $name]);
        \assert($found instanceof Alias || $found instanceof Domain || $found instanceof FetchmailAccount || $found instanceof User);

        return (int) $found->getId();
    }

    /**
     * @param class-string $entity
     */
    private function assertExists(string $entity, int $id): void
    {
        $this->entityManager->clear();
        static::assertNotNull($this->entityManager->find($entity, $id));
    }

    /**
     * @param class-string $entity
     */
    private function assertGone(string $entity, int $id): void
    {
        $this->entityManager->clear();
        static::assertNull($this->entityManager->find($entity, $id));
    }

    private function findUser(string $emailAddress): User
    {
        $repository = $this->entityManager->getRepository(User::class);
        \assert($repository instanceof UserRepository);
        $user = $repository->findOneByEmailAddress($emailAddress);
        static::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function assertResponseContains(string $needle): void
    {
        static::assertStringContainsString($needle, (string) $this->client->getResponse()->getContent());
    }

    private function assertResponseNotContains(string $needle): void
    {
        static::assertStringNotContainsString($needle, (string) $this->client->getResponse()->getContent());
    }
}

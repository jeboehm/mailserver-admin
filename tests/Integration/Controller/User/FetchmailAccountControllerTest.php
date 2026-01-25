<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\User;

use App\Controller\Admin\DashboardController;
use App\Controller\User\FetchmailAccountController;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Repository\FetchmailAccountRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\UserTrait;

class FetchmailAccountControllerTest extends AbstractCrudTestCase
{
    use UserTrait;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginClient($this->client);
    }

    public function testListFetchmailAccounts(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $account = new FetchmailAccount();
        $account->setUser($user);
        $account->setHost('fetch.example.com');
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('fetchuser');
        $account->setPassword('password');
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $accountId = $account->getId();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->assertIndexEntityActionExists('edit', $accountId);
        $this->assertIndexEntityActionExists('delete', $accountId);
        $this->assertGlobalActionExists('new');
    }

    public function testCreateFetchmailAccount(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Fetchmail Account');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'FetchmailAccount[host]' => 'mail.example.com',
            'FetchmailAccount[port]' => 993,
            'FetchmailAccount[protocol]' => 'imap',
            'FetchmailAccount[username]' => 'testuser',
            'FetchmailAccount[password]' => 'testpassword',
            'FetchmailAccount[ssl]' => true,
            'FetchmailAccount[verifySsl]' => true,
        ]);
        static::assertResponseIsSuccessful();

        $fetchmailRepository = $this->entityManager->getRepository(FetchmailAccount::class);
        \assert($fetchmailRepository instanceof FetchmailAccountRepository);
        $account = $fetchmailRepository->findOneBy(['user' => $user, 'host' => 'mail.example.com', 'username' => 'testuser']);
        static::assertInstanceOf(FetchmailAccount::class, $account);
        static::assertEquals('mail.example.com', $account->getHost());
        static::assertEquals(993, $account->getPort());
        static::assertEquals('imap', $account->getProtocol());
        static::assertEquals('testuser', $account->getUsername());
        static::assertTrue($account->isSsl());
        static::assertTrue($account->isVerifySsl());
    }

    public function testCreateFetchmailAccountWithPop3(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Fetchmail Account');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'FetchmailAccount[host]' => 'pop.example.com',
            'FetchmailAccount[port]' => 995,
            'FetchmailAccount[protocol]' => 'pop3',
            'FetchmailAccount[username]' => 'popuser',
            'FetchmailAccount[password]' => 'poppassword',
            'FetchmailAccount[ssl]' => true,
            'FetchmailAccount[verifySsl]' => false,
        ]);
        static::assertResponseIsSuccessful();

        $fetchmailRepository = $this->entityManager->getRepository(FetchmailAccount::class);
        \assert($fetchmailRepository instanceof FetchmailAccountRepository);
        $account = $fetchmailRepository->findOneBy(['user' => $user, 'host' => 'pop.example.com', 'username' => 'popuser']);
        static::assertInstanceOf(FetchmailAccount::class, $account);
        static::assertEquals('pop3', $account->getProtocol());
        static::assertTrue($account->isSsl());
        static::assertFalse($account->isVerifySsl());
    }

    public function testEditFetchmailAccount(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $account = new FetchmailAccount();
        $account->setUser($user);
        $account->setHost('original.example.com');
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('originaluser');
        $account->setPassword('originalpassword');
        $account->setSsl(false);
        $account->setVerifySsl(true);

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($account->getId()));
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Save changes', [
            'FetchmailAccount[host]' => 'updated.example.com',
            'FetchmailAccount[port]' => 993,
            'FetchmailAccount[ssl]' => true,
        ]);
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $fetchmailRepository = $this->entityManager->getRepository(FetchmailAccount::class);
        \assert($fetchmailRepository instanceof FetchmailAccountRepository);
        $updatedAccount = $fetchmailRepository->find($account->getId());
        static::assertInstanceOf(FetchmailAccount::class, $updatedAccount);
        static::assertEquals('updated.example.com', $updatedAccount->getHost());
        static::assertEquals(993, $updatedAccount->getPort());
        static::assertTrue($updatedAccount->isSsl());
    }

    public function testEditFetchmailAccountWithoutPassword(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $account = new FetchmailAccount();
        $account->setUser($user);
        $account->setHost('nopass.example.com');
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('nopassuser');
        $account->setPassword('originalpassword');
        $originalPassword = $account->getPassword();

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($account->getId()));
        static::assertResponseIsSuccessful();

        // Submit form without password field (leave empty)
        $this->client->submitForm('Save changes', [
            'FetchmailAccount[host]' => 'nopass.example.com',
            'FetchmailAccount[port]' => 993,
        ]);
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $fetchmailRepository = $this->entityManager->getRepository(FetchmailAccount::class);
        \assert($fetchmailRepository instanceof FetchmailAccountRepository);
        $updatedAccount = $fetchmailRepository->find($account->getId());
        static::assertInstanceOf(FetchmailAccount::class, $updatedAccount);
        // Password should remain unchanged
        static::assertEquals($originalPassword, $updatedAccount->getPassword());
    }

    public function testCreateDuplicateFetchmailAccount(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $existingAccount = new FetchmailAccount();
        $existingAccount->setUser($user);
        $existingAccount->setHost('duplicate.example.com');
        $existingAccount->setPort(143);
        $existingAccount->setProtocol('imap');
        $existingAccount->setUsername('duplicateuser');
        $existingAccount->setPassword('password');

        $this->entityManager->persist($existingAccount);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Fetchmail Account');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'FetchmailAccount[host]' => 'duplicate.example.com',
            'FetchmailAccount[port]' => 143,
            'FetchmailAccount[protocol]' => 'imap',
            'FetchmailAccount[username]' => 'duplicateuser',
            'FetchmailAccount[password]' => 'password',
        ]);

        static::assertSelectorTextContains('.invalid-feedback', 'This value is already used.');
    }

    public function testPasswordFieldOnEditForm(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $account = new FetchmailAccount();
        $account->setUser($user);
        $account->setHost('password-field.example.com');
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('passworduser');
        $account->setPassword('originalpassword');

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($account->getId()));
        static::assertResponseIsSuccessful();

        $passwordField = $this->client->getCrawler()->filter('input[name="FetchmailAccount[password]"]');
        static::assertCount(1, $passwordField);
        static::assertSame('password', $passwordField->attr('type'));
    }

    public function testPasswordFieldOnNewForm(): void
    {
        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Fetchmail Account');
        static::assertResponseIsSuccessful();

        $passwordField = $this->client->getCrawler()->filter('input[name="FetchmailAccount[password]"]');
        static::assertCount(1, $passwordField);
        static::assertSame('password', $passwordField->attr('type'));
    }

    public function testEditWithNewPassword(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $account = new FetchmailAccount();
        $account->setUser($user);
        $account->setHost('newpass.example.com');
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('newpassuser');
        $account->setPassword('originalpassword');

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($account->getId()));
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Save changes', [
            'FetchmailAccount[host]' => 'newpass.example.com',
            'FetchmailAccount[password]' => 'newpassword123',
        ]);
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $fetchmailRepository = $this->entityManager->getRepository(FetchmailAccount::class);
        \assert($fetchmailRepository instanceof FetchmailAccountRepository);
        $updatedAccount = $fetchmailRepository->find($account->getId());
        static::assertInstanceOf(FetchmailAccount::class, $updatedAccount);
        static::assertNotEquals('originalpassword', $updatedAccount->getPassword());
    }

    public function testCreateEntitySetsCurrentUser(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        \assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add Fetchmail Account');
        static::assertResponseIsSuccessful();

        $form = $this->client->getCrawler()->selectButton('Create')->form();
        $form['FetchmailAccount[host]'] = 'autouser.example.com';
        $form['FetchmailAccount[port]'] = '143';
        $form['FetchmailAccount[protocol]'] = 'imap';
        $form['FetchmailAccount[username]'] = 'autouser';
        $form['FetchmailAccount[password]'] = 'password';

        $this->client->submit($form);
        static::assertResponseIsSuccessful();

        $fetchmailRepository = $this->entityManager->getRepository(FetchmailAccount::class);
        \assert($fetchmailRepository instanceof FetchmailAccountRepository);
        $account = $fetchmailRepository->findOneBy(['user' => $user, 'host' => 'autouser.example.com']);
        static::assertInstanceOf(FetchmailAccount::class, $account);
        static::assertEquals($user->getId(), $account->getUser()->getId());
    }

    protected function getControllerFqcn(): string
    {
        return FetchmailAccountController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }
}

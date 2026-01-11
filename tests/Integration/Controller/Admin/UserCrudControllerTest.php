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
use App\Controller\Admin\UserCrudController;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\UserTrait;

class UserCrudControllerTest extends AbstractCrudTestCase
{
    use UserTrait;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginClient($this->client);
    }

    public function testListUsers(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);
        $userId = $user->getId();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();
        // Check that the page contains the admin user - the exact selector may vary
        static::assertSelectorTextContains('body', 'admin@example.com');

        $this->assertIndexEntityActionExists('edit', $userId);
        $this->assertIndexEntityActionNotExists('delete', $userId);
        $this->assertGlobalActionExists('new');
    }

    public function testListUsersCanDeleteOtherUsers(): void
    {
        $domain = new Domain();
        $domain->setName('example.invalid');

        $user = new User();
        $user->setDomain($domain);
        $user->setName('max');

        $this->entityManager->persist($user);
        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        $userId = $user->getId();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());

        static::assertResponseIsSuccessful();

        $this->assertIndexEntityActionExists('edit', $userId);
        $this->assertIndexEntityActionExists('delete', $userId);
        $this->assertGlobalActionExists('new');
    }

    public function testCreateUser(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add User');
        static::assertResponseIsSuccessful();
        static::assertAnySelectorTextNotContains('.form-text.form-help', 'Leave empty to keep the current password.');

        $this->client->submitForm('Create', [
            'User[name]' => 'newuser',
            'User[plainPassword][first]' => 'password123',
            'User[plainPassword][second]' => 'password123',
            'User[quota]' => 1000,
        ]);
        static::assertResponseIsSuccessful();

        $userRepository = $this->entityManager->getRepository(User::class);
        assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneBy(['name' => 'newuser', 'domain' => $domain]);
        static::assertInstanceOf(User::class, $user);
        static::assertEquals('newuser', $user->getName());
        static::assertEquals(1000, $user->getQuota());
        static::assertTrue($user->getEnabled());
    }

    public function testEditUser(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $user = new User();
        $user->setDomain($domain);
        $user->setName('edituser');
        $user->setPlainPassword('password123');
        $user->setQuota(500);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($user->getId()));
        static::assertResponseIsSuccessful();
        static::assertAnySelectorTextContains('.form-text.form-help', 'Leave empty to keep the current password.');

        $this->client->submitForm('Save changes', [
            'User[quota]' => 2000,
            'User[sendOnly]' => true,
        ]);
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $userRepository = $this->entityManager->getRepository(User::class);
        assert($userRepository instanceof UserRepository);
        $updatedUser = $userRepository->find($user->getId());
        static::assertInstanceOf(User::class, $updatedUser);
        static::assertEquals(2000, $updatedUser->getQuota());
        static::assertTrue($updatedUser->getSendOnly());
        static::assertNotEmpty($updatedUser->getPassword());
    }

    public function testEditUserWithoutPassword(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $user = new User();
        $user->setDomain($domain);
        $user->setName('nopassuser');
        $user->setPlainPassword('originalpassword');

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        // Get the hashed password after it's been processed by PasswordService
        $originalPassword = $user->getPassword();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateEditFormUrl($user->getId()));
        static::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $form = $crawler->selectButton('Save changes')->form();
        $form['User[quota]'] = '1500';
        $this->client->submit($form);
        static::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $userRepository = $this->entityManager->getRepository(User::class);
        assert($userRepository instanceof UserRepository);
        $updatedUser = $userRepository->find($user->getId());
        static::assertInstanceOf(User::class, $updatedUser);
        static::assertStringStartsWith('$2y$', $updatedUser->getPassword());
        static::assertEquals($originalPassword, $updatedUser->getPassword());
    }

    public function testCreateDuplicateUser(): void
    {
        $domainRepository = $this->entityManager->getRepository(Domain::class);
        assert($domainRepository instanceof DomainRepository);
        $domain = $domainRepository->findOneBy(['name' => 'example.com']);
        static::assertInstanceOf(Domain::class, $domain);

        $existingUser = new User();
        $existingUser->setDomain($domain);
        $existingUser->setName('duplicateuser');
        $existingUser->setPlainPassword('password123');

        $this->entityManager->persist($existingUser);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request(Request::METHOD_GET, $this->generateIndexUrl());
        static::assertResponseIsSuccessful();

        $this->client->clickLink('Add User');
        static::assertResponseIsSuccessful();

        $this->client->submitForm('Create', [
            'User[name]' => 'duplicateuser',
            'User[plainPassword][first]' => 'password123',
            'User[plainPassword][second]' => 'password123',
            'User[quota]' => 1000,
        ]);

        static::assertSelectorTextContains('.invalid-feedback', 'This value is already used.');
    }

    protected function getControllerFqcn(): string
    {
        return UserCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\User;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Tests\Integration\Helper\UserTrait;

class ChangePasswordActionTest extends WebTestCase
{
    use UserTrait;

    private KernelBrowser $client;

    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $userRepository = $this->client->getContainer()->get(UserRepository::class);
        $this->user = $userRepository->findOneByEmailAddress('admin@example.com');
        $this->user->setPlainPassword('changeme');
        $this->user->setPassword('changeme');

        $this->client->getContainer()->get(EntityManagerInterface::class)->flush();
        $this->loginClient($this->client);
    }

    public function testChangePasswordPageRenders(): void
    {
        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_change_password_index')->generateUrl();

        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="change_password[currentPassword]"]');
        self::assertSelectorExists('input[name="change_password[plainPassword][first]"]');
        self::assertSelectorExists('input[name="change_password[plainPassword][second]"]');
    }

    public function testChangePasswordSuccessfully(): void
    {
        $userRepository = $this->client->getContainer()->get(UserRepository::class);
        assert($userRepository instanceof UserRepository);
        $user = $userRepository->findOneByEmailAddress('admin@example.com');
        static::assertInstanceOf(User::class, $user);
        $originalPassword = $user->getPassword();

        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_change_password_index')->generateUrl();
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $plainPassword = 'neWP4ssword123!!';
        $this->client->submitForm('Change Password', [
            'change_password[currentPassword]' => 'changeme',
            'change_password[plainPassword][first]' => $plainPassword,
            'change_password[plainPassword][second]' => $plainPassword,
        ]);

        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        assert($adminUrlGenerator instanceof AdminUrlGenerator);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'Your password has been updated.');

        // Verify password was changed
        $this->client->getContainer()->get('doctrine')->getManager()->clear();
        $updatedUser = $userRepository->find($user->getId());
        static::assertInstanceOf(User::class, $updatedUser);
        static::assertNotEquals($originalPassword, $updatedUser->getPassword());

        // Verify new password works
        $passwordHasherFactory = $this->client->getContainer()->get(PasswordHasherFactoryInterface::class);
        assert($passwordHasherFactory instanceof PasswordHasherFactoryInterface);
        $passwordHasher = $passwordHasherFactory->getPasswordHasher($updatedUser);
        static::assertTrue($passwordHasher->verify($updatedUser->getPassword(), $plainPassword));
    }
}

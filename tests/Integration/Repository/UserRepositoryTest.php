<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Repository;

use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    /**
     * MySQL collations compare case insensitively, the PostgreSQL default
     * collation does not. Mail addresses must resolve either way on both.
     */
    #[DataProvider('addressSpellingProvider')]
    public function testFindOneByEmailAddressIgnoresCase(string $address): void
    {
        $user = $this->repository->findOneByEmailAddress($address);

        $this->assertNotNull($user, \sprintf('No user found for "%s".', $address));
        $this->assertSame('admin@example.com', $user->getUserIdentifier());
    }

    public static function addressSpellingProvider(): array
    {
        return [
            'lowercase' => ['admin@example.com'],
            'mixed case' => ['Admin@Example.Com'],
            'uppercase' => ['ADMIN@EXAMPLE.COM'],
            'uppercase domain' => ['admin@EXAMPLE.COM'],
            'uppercase local part' => ['ADMIN@example.com'],
        ];
    }

    public function testFindOneByEmailAddressReturnsNullForUnknownAddress(): void
    {
        $this->assertNull($this->repository->findOneByEmailAddress('nobody@example.com'));
    }

    public function testFindOneByEmailAddressReturnsNullWithoutDomainPart(): void
    {
        $this->assertNull($this->repository->findOneByEmailAddress('admin'));
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Service\Dovecot;

use App\Entity\Domain;
use App\Entity\User;
use App\Exception\Dovecot\DoveadmConnectionException;
use App\Service\Dovecot\DoveadmHttpClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuthCacheInvalidationListenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    /** @var list<list<string>> */
    private array $flushed = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $doveadm = self::createStub(DoveadmHttpClient::class);
        $doveadm->method('flushAuthCache')->willReturnCallback(function (array $addresses): void {
            $this->flushed[] = $addresses;
        });
        self::getContainer()->set(DoveadmHttpClient::class, $doveadm);

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCreatingAUserFlushesItsAddressToClearCachedRefusals(): void
    {
        $this->createUser('cache-new');

        self::assertSame([['cache-new@cache.example.com']], $this->flushed);
    }

    public function testDisablingAUserFlushesItsAddress(): void
    {
        $user = $this->createExistingUser('cache-disable');

        $user->setEnabled(false);
        $this->entityManager->flush();

        self::assertSame([['cache-disable@cache.example.com']], $this->flushed);
    }

    public function testEnablingAUserFlushesItsAddress(): void
    {
        $user = $this->createExistingUser('cache-enable', enabled: false);

        $user->setEnabled(true);
        $this->entityManager->flush();

        self::assertSame([['cache-enable@cache.example.com']], $this->flushed);
    }

    public function testChangingSendOnlyFlushesItsAddress(): void
    {
        $user = $this->createExistingUser('cache-sendonly');

        $user->setSendOnly(true);
        $this->entityManager->flush();

        self::assertSame([['cache-sendonly@cache.example.com']], $this->flushed);
    }

    public function testChangingThePasswordFlushesItsAddress(): void
    {
        $user = $this->createExistingUser('cache-password');

        $user->setPassword('');
        $user->setPlainPassword('a-new-password-1234');
        $this->entityManager->flush();

        self::assertSame([['cache-password@cache.example.com']], $this->flushed);
    }

    public function testDeletingAUserFlushesItsAddress(): void
    {
        $user = $this->createExistingUser('cache-delete');

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        self::assertSame([['cache-delete@cache.example.com']], $this->flushed);
    }

    public function testRenamingAUserFlushesTheOldAndTheNewAddress(): void
    {
        $user = $this->createExistingUser('cache-old');

        $user->setName('cache-renamed');
        $this->entityManager->flush();

        self::assertSame([['cache-renamed@cache.example.com', 'cache-old@cache.example.com']], $this->flushed);
    }

    public function testMovingAUserToAnotherDomainFlushesTheOldAndTheNewAddress(): void
    {
        $user = $this->createExistingUser('cache-move');
        $other = new Domain();
        $other->setName('cache-other.example.com');
        $this->entityManager->persist($other);

        $user->setDomain($other);
        $this->entityManager->flush();

        self::assertSame([['cache-move@cache-other.example.com', 'cache-move@cache.example.com']], $this->flushed);
    }

    public function testChangesInOneFlushAreSentTogether(): void
    {
        $first = $this->createExistingUser('cache-first');
        $second = $this->createExistingUser('cache-second');

        $first->setEnabled(false);
        $second->setEnabled(false);
        $this->entityManager->flush();

        self::assertCount(1, $this->flushed);
        self::assertEqualsCanonicalizing(
            ['cache-first@cache.example.com', 'cache-second@cache.example.com'],
            $this->flushed[0],
        );
    }

    public function testChangesThatDoNotAffectLoginsDoNotFlush(): void
    {
        $user = $this->createExistingUser('cache-quota');

        $user->setQuota(123);
        $this->entityManager->flush();

        self::assertSame([], $this->flushed);
    }

    public function testAFlushWithoutChangesDoesNotCallDoveadm(): void
    {
        $this->createExistingUser('cache-unchanged');

        $this->entityManager->flush();

        self::assertSame([], $this->flushed);
    }

    public function testAddressesAreLowerCaseBecauseDovecotCachesThemThatWay(): void
    {
        $domain = new Domain();
        $domain->setName('Cache-Upper.Example.COM');
        $this->entityManager->persist($domain);

        $this->createUser('cache-upper', domain: $domain);

        self::assertSame([['cache-upper@cache-upper.example.com']], $this->flushed);
    }

    public function testAFailedFlushDoesNotFailTheSave(): void
    {
        $user = $this->createExistingUser('cache-failure');

        $doveadm = $this->createMock(DoveadmHttpClient::class);
        $doveadm->expects(self::once())->method('flushAuthCache')
            ->willThrowException(new DoveadmConnectionException('unreachable'));
        self::ensureKernelShutdown();
        self::bootKernel();
        self::getContainer()->set(DoveadmHttpClient::class, $doveadm);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $user = $entityManager->getRepository(User::class)->find($user->getId());
        $user->setEnabled(false);
        $entityManager->flush();
        $entityManager->clear();

        self::assertFalse($entityManager->getRepository(User::class)->find($user->getId())->getEnabled());
    }

    private function createExistingUser(string $name, bool $enabled = true): User
    {
        $user = $this->createUser($name, enabled: $enabled);
        $this->flushed = [];

        return $user;
    }

    private function createUser(string $name, bool $enabled = true, ?Domain $domain = null): User
    {
        if (null === $domain) {
            $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['name' => 'cache.example.com']);
        }

        if (null === $domain) {
            $domain = new Domain();
            $domain->setName('cache.example.com');
            $this->entityManager->persist($domain);
        }

        $user = new User();
        $user->setName($name);
        $user->setDomain($domain);
        $user->setPlainPassword('an-initial-password-1234');
        $user->setEnabled($enabled);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Service\MailboxCredential;

use App\Entity\Domain;
use App\Entity\User;
use App\Exception\MailboxCredential\TooManyImapSessionsException;
use App\Repository\UserRepository;
use App\Service\MailboxCredential\MailboxCredentialKind;
use App\Service\MailboxCredential\MailboxCredentials;
use App\Service\MailboxCredential\MailboxProtocol;
use App\Service\MailboxCredential\ShortLivedCredential;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MailboxCredentialsTest extends KernelTestCase
{
    private MailboxCredentials $credentials;
    private Connection $connection;
    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->credentials = static::getContainer()->get(MailboxCredentials::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmailAddress('admin@example.com');
        self::assertInstanceOf(User::class, $user);
        $this->user = $user;
    }

    public function testLoginIsThePrefixedAddressOfTheUser(): void
    {
        $login = $this->credentials->withCredential(
            $this->user,
            MailboxProtocol::Imap,
            static fn (ShortLivedCredential $credential): string => $credential->login,
        );

        self::assertSame('cred#admin@example.com', $login);
    }

    public function testRowHoldsTheHashForOneProtocolWhileTheOperationRuns(): void
    {
        $row = $this->credentials->withCredential(
            $this->user,
            MailboxProtocol::Smtp,
            fn (ShortLivedCredential $credential): array => [
                'secret' => $credential->secret,
                'row' => $this->connection->fetchAssociative(
                    'SELECT * FROM mail_mailbox_credentials WHERE secret_hash = ?',
                    [hash('sha256', $credential->secret)],
                ),
            ],
        );

        self::assertIsArray($row['row'], 'The row is stored under the SHA-256 of the secret.');
        self::assertGreaterThanOrEqual(64, \strlen($row['secret']));
        self::assertSame($this->user->getId(), (int) $row['row']['user_id']);
        self::assertSame('short_lived', $row['row']['kind']);
        self::assertFalse((bool) $row['row']['allow_imap']);
        self::assertTrue((bool) $row['row']['allow_smtp']);
        self::assertNull($row['row']['name']);
    }

    public function testRowIsRemovedAfterTheOperation(): void
    {
        $this->credentials->withCredential($this->user, MailboxProtocol::Imap, function (): void {
            self::assertSame(1, $this->countRows());
        });

        self::assertSame(0, $this->countRows());
    }

    public function testRowIsRemovedWhenTheOperationFails(): void
    {
        try {
            $this->credentials->withCredential($this->user, MailboxProtocol::Imap, $this->failingOperation(...));
            self::fail('The failure of the operation must reach the caller.');
        } catch (\DomainException $e) {
            self::assertSame('login failed', $e->getMessage());
        }

        self::assertSame(0, $this->countRows());
    }

    public function testExpiryIsAboutAMinuteAheadOnTheDatabaseClock(): void
    {
        $secondsLeft = $this->credentials->withCredential($this->user, MailboxProtocol::Imap, function (): int {
            $row = $this->connection->fetchAssociative(
                'SELECT expires_at, CURRENT_TIMESTAMP AS database_now FROM mail_mailbox_credentials',
            );
            self::assertIsArray($row);

            return new \DateTimeImmutable($row['expires_at'])->getTimestamp()
                - new \DateTimeImmutable($row['database_now'])->getTimestamp();
        });

        self::assertGreaterThanOrEqual(55, $secondsLeft);
        self::assertLessThanOrEqual(61, $secondsLeft, 'A column without fractions can round up.');
    }

    public function testConcurrentCredentialsOfOneUserAreIndependent(): void
    {
        $this->credentials->withCredential($this->user, MailboxProtocol::Imap, function (ShortLivedCredential $outer): void {
            $this->credentials->withCredential($this->user, MailboxProtocol::Imap, function (ShortLivedCredential $inner) use ($outer): void {
                self::assertNotSame($outer->secret, $inner->secret);
                self::assertSame(2, $this->countRows());
            });

            self::assertSame(1, $this->countRows());
        });
    }

    public function testIssuingPurgesExpiredShortLivedCredentialsOfAnyUser(): void
    {
        $this->storeRow($this->otherUser(), MailboxCredentialKind::ShortLived, '-1 minute');

        $this->credentials->withCredential($this->user, MailboxProtocol::Smtp, function (): void {
            self::assertSame(1, $this->countRows(), 'Only the new credential is left.');
        });
    }

    public function testPurgeLeavesAppPasswordsAndValidCredentialsAlone(): void
    {
        $this->storeRow($this->user, MailboxCredentialKind::AppPassword, '-1 minute');
        $this->storeRow($this->user, MailboxCredentialKind::AppPassword, null);
        $this->storeRow($this->user, MailboxCredentialKind::ShortLived, '+1 minute');

        $this->credentials->withCredential($this->user, MailboxProtocol::Smtp, static function (): void {
        });

        self::assertSame(3, $this->countRows());
    }

    public function testImapSessionsOfOneUserAreLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->storeRow($this->user, MailboxCredentialKind::ShortLived, '+1 minute');
        }

        $ran = false;

        try {
            $this->credentials->withCredential($this->user, MailboxProtocol::Imap, static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('The sixth parallel session must be refused.');
        } catch (TooManyImapSessionsException) {
            self::assertFalse($ran);
        }

        self::assertSame(5, $this->countRows(), 'The refused credential is not left behind.');
    }

    public function testLimitOnlyCountsLiveImapSessionsOfTheSameUser(): void
    {
        $other = $this->otherUser();

        for ($i = 0; $i < 5; ++$i) {
            $this->storeRow($other, MailboxCredentialKind::ShortLived, '+1 minute');
            $this->storeRow($this->user, MailboxCredentialKind::AppPassword, null);
        }

        $login = $this->credentials->withCredential($this->user, MailboxProtocol::Imap, static fn (ShortLivedCredential $credential): string => $credential->login);

        self::assertSame('cred#admin@example.com', $login);
    }

    public function testSmtpIsNotLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->storeRow($this->user, MailboxCredentialKind::ShortLived, '+1 minute');
        }

        $login = $this->credentials->withCredential($this->user, MailboxProtocol::Smtp, static fn (ShortLivedCredential $credential): string => $credential->login);

        self::assertSame('cred#admin@example.com', $login);
    }

    public function testDeletingAUserRemovesTheirCredentials(): void
    {
        $other = $this->otherUser();
        $this->storeRow($other, MailboxCredentialKind::AppPassword, null);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($other);
        $entityManager->flush();

        self::assertSame(0, $this->countRows());
    }

    private function failingOperation(): void
    {
        throw new \DomainException('login failed');
    }

    private function countRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mail_mailbox_credentials');
    }

    private function otherUser(): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $domain = $entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $domain);

        $user = new User();
        $user->setName('credentials-other');
        $user->setDomain($domain);
        $user->setPassword('irrelevant');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function storeRow(User $user, MailboxCredentialKind $kind, ?string $expiry): void
    {
        $this->connection->insert('mail_mailbox_credentials', [
            'user_id' => $user->getId(),
            'secret_hash' => bin2hex(random_bytes(32)),
            'kind' => $kind->value,
            'allow_imap' => true,
            'allow_smtp' => false,
            'expires_at' => null === $expiry ? null : new \DateTimeImmutable($this->databaseNow())->modify($expiry)->format('Y-m-d H:i:s'),
            'name' => MailboxCredentialKind::AppPassword === $kind ? bin2hex(random_bytes(8)) : null,
            'created_at' => $this->databaseNow(),
        ], ['allow_imap' => ParameterType::BOOLEAN, 'allow_smtp' => ParameterType::BOOLEAN]);
    }

    private function databaseNow(): string
    {
        return (string) $this->connection->fetchOne('SELECT CURRENT_TIMESTAMP');
    }
}

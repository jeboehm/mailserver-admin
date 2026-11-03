<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Entity;

use App\Entity\MailboxCredential;
use App\Entity\User;
use App\Service\MailboxCredential\MailboxCredentialKind;
use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Framework\TestCase;

/**
 * This entity only maps the table. Writing happens in MailboxCredentials, through DBAL.
 */
class MailboxCredentialTest extends TestCase
{
    public function testDoctrineNeverWritesIt(): void
    {
        $mapping = new \ReflectionClass(MailboxCredential::class)->getAttributes(Entity::class)[0]->newInstance();

        self::assertTrue($mapping->readOnly);
    }

    public function testNothingCanSetAValue(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            new \ReflectionClass(MailboxCredential::class)->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame([], array_filter($methods, static fn (string $method): bool => str_starts_with($method, 'set')));
    }

    public function testExposesAShortLivedRow(): void
    {
        $user = new User();
        $expiresAt = new \DateTimeImmutable('2026-01-01 12:01:00');

        $credential = $this->hydrated([
            'user' => $user,
            'secretHash' => str_repeat('a', 64),
            'kind' => MailboxCredentialKind::ShortLived,
            'allowImap' => true,
            'allowSmtp' => false,
            'expiresAt' => $expiresAt,
            'createdAt' => new \DateTimeImmutable('2026-01-01 12:00:00'),
        ]);

        self::assertSame($user, $credential->getUser());
        self::assertSame(MailboxCredentialKind::ShortLived, $credential->getKind());
        self::assertTrue($credential->allowsImap());
        self::assertFalse($credential->allowsSmtp());
        self::assertSame($expiresAt, $credential->getExpiresAt());
        self::assertNull($credential->getName());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function hydrated(array $values): MailboxCredential
    {
        $credential = new \ReflectionClass(MailboxCredential::class)->newInstanceWithoutConstructor();

        foreach ($values as $property => $value) {
            new \ReflectionProperty(MailboxCredential::class, $property)->setValue($credential, $value);
        }

        return $credential;
    }
}

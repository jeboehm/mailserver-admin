<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DKIM;

use App\Service\DKIM\KeyGenerationService;
use App\Service\DKIM\KeyPair;
use App\Service\DKIM\KeyRecreationService;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\DkimPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Tests\Unit\Service\Security\Access\ActorFixturesTrait;

class KeyRecreationServiceTest extends TestCase
{
    use ActorFixturesTrait;

    public function testAdminGetsANewPrivateKeySaved(): void
    {
        $domain = $this->domain();
        $domain->setDkimPrivateKey('old-key');

        $keyGeneration = $this->createStub(KeyGenerationService::class);
        $keyGeneration->method('createKeyPair')->willReturn(new KeyPair('public', 'new-key'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        new KeyRecreationService($keyGeneration, $entityManager, new DkimPolicy())
            ->recreate($this->actor(ActorRole::Admin, $domain), $domain);

        self::assertSame('new-key', $domain->getDkimPrivateKey());
    }

    /**
     * @return iterable<string, array{ActorRole}>
     */
    public static function nonAdmins(): iterable
    {
        yield 'domain admin' => [ActorRole::DomainAdmin];
        yield 'regular user' => [ActorRole::User];
    }

    #[DataProvider('nonAdmins')]
    public function testEveryoneElseIsRejectedAndTheKeyStays(ActorRole $role): void
    {
        $domain = $this->domain();
        $domain->setDkimPrivateKey('old-key');

        $keyGeneration = $this->createMock(KeyGenerationService::class);
        $keyGeneration->expects($this->never())->method('createKeyPair');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            new KeyRecreationService($keyGeneration, $entityManager, new DkimPolicy())
                ->recreate($this->actor($role, $domain), $domain);
            self::fail('Expected the recreation to be denied.');
        } catch (AccessDeniedException) {
            self::assertSame('old-key', $domain->getDkimPrivateKey());
        }
    }
}

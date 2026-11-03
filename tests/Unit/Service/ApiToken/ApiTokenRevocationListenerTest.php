<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\ApiToken;

use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenRevocationListener;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiTokenRevocationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool, string|null, bool}>
     */
    public static function changes(): iterable
    {
        yield 'hashed password changed' => [['password' => ['old', 'new']], true, null, true];
        yield 'plain password waiting to be hashed' => [[], true, 'new password', true];
        yield 'disabled' => [['enabled' => [true, false]], false, null, true];
        yield 'enabled again' => [['enabled' => [false, true]], true, null, false];
        yield 'empty plain password, as the forms leave it' => [['quota' => [0, 5]], true, '', false];
        yield 'something else' => [['quota' => [0, 5]], true, null, false];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('changes')]
    public function testRevokesTokensOnlyForAChangeThatInvalidatesThem(array $changes, bool $enabled, ?string $plainPassword, bool $revoked): void
    {
        $user = $this->user($enabled, $plainPassword);
        $token = new ApiToken($user, 'token', 'hash', [ApiTokenScope::ManageRead], new \DateTimeImmutable('+1 day'));

        new ApiTokenRevocationListener()->onFlush($this->flushOf($user, $changes, [$token]));

        self::assertSame($revoked, $token->isRevoked());
    }

    public function testIgnoresOtherEntities(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('findBy');

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([new Domain()]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->method('getRepository')->willReturn($repository);

        new ApiTokenRevocationListener()->onFlush(new OnFlushEventArgs($entityManager));
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<ApiToken>       $tokens
     */
    private function flushOf(User $user, array $changes, array $tokens): OnFlushEventArgs
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn($tokens);

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([$user]);
        $unitOfWork->method('getEntityChangeSet')->willReturn($changes);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->method('getRepository')->willReturn($repository);

        return new OnFlushEventArgs($entityManager);
    }

    private function user(bool $enabled, ?string $plainPassword): User
    {
        $user = new User();
        $user->setEnabled($enabled);
        $user->setPlainPassword($plainPassword);

        return $user;
    }
}

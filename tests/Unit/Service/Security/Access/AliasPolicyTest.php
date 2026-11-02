<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\AliasPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AliasPolicyTest extends TestCase
{
    use ActorFixturesTrait;

    /**
     * @return iterable<string, array{ActorRole, bool, bool}>
     */
    public static function deleters(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, true, false];
        yield 'regular user' => [ActorRole::User, false, false];
    }

    #[DataProvider('deleters')]
    public function testCanDelete(ActorRole $role, bool $ownDomain, bool $foreignDomain): void
    {
        $domain = $this->domain();
        $actor = $this->actor($role, $domain);
        $policy = new AliasPolicy();

        self::assertSame($ownDomain, $policy->canDelete($actor, $this->alias($domain)));
        self::assertSame($foreignDomain, $policy->canDelete($actor, $this->alias($this->domain('foreign.example'))));
    }

    public function testDeletingAForeignAliasIsRejected(): void
    {
        $this->expectException(AccessDeniedException::class);

        new AliasPolicy()->denyUnlessCanDelete(
            $this->actor(ActorRole::DomainAdmin, $this->domain()),
            $this->alias($this->domain('foreign.example'))
        );
    }

    #[DoesNotPerformAssertions]
    public function testDeletingAnOwnAliasIsAllowed(): void
    {
        $domain = $this->domain();

        new AliasPolicy()->denyUnlessCanDelete($this->actor(ActorRole::DomainAdmin, $domain), $this->alias($domain));
    }

    private function alias(Domain $domain): Alias
    {
        $alias = new Alias();
        $alias->setDomain($domain);

        return $alias;
    }
}

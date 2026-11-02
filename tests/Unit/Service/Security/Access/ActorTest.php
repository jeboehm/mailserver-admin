<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\Domain;
use App\Entity\User;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActorTest extends TestCase
{
    use ActorFixtures;

    /**
     * @return iterable<string, array{ActorRole, bool, bool}>
     */
    public static function ownVsForeignDomain(): iterable
    {
        yield 'admin manages every domain' => [ActorRole::Admin, true, true];
        yield 'domain admin manages only their own domain' => [ActorRole::DomainAdmin, true, false];
        yield 'regular user manages no domain' => [ActorRole::User, false, false];
    }

    #[DataProvider('ownVsForeignDomain')]
    public function testManagesDomain(ActorRole $role, bool $own, bool $foreign): void
    {
        $domain = $this->domain();
        $actor = $this->actor($role, $domain);

        self::assertSame($own, $actor->managesDomain($domain));
        self::assertSame($foreign, $actor->managesDomain($this->domain('foreign.example')));
    }

    public function testDomainAdminWithoutDomainManagesNothing(): void
    {
        $actor = new Actor(new User(), ActorRole::DomainAdmin);

        self::assertFalse($actor->managesDomain(null));
        self::assertFalse($actor->managesDomain($this->domain()));
    }

    public function testDomainComparisonSurvivesADetachedCopy(): void
    {
        $domain = $this->withId($this->domain(), 7);
        $copy = $this->withId($this->domain(), 7);

        self::assertTrue($this->actor(ActorRole::DomainAdmin, $domain)->managesDomain($copy));
    }

    public function testIsSelfRecognisesTheSameInstance(): void
    {
        $actor = $this->actor(ActorRole::Admin, $this->domain());

        self::assertTrue($actor->isSelf($actor->user));
    }

    public function testIsSelfRecognisesADetachedCopy(): void
    {
        $domain = $this->domain();
        $actor = new Actor($this->withId($this->user($domain), 7), ActorRole::Admin);

        self::assertTrue($actor->isSelf($this->withId($this->user($domain), 7)));
    }

    public function testUnsavedUsersAreNotTheSamePerson(): void
    {
        $domain = $this->domain();
        $actor = $this->actor(ActorRole::Admin, $domain);

        self::assertFalse($actor->isSelf($this->user($domain)));
    }

    /**
     * @template T of User|Domain
     *
     * @param T $entity
     *
     * @return T
     */
    private function withId(User|Domain $entity, int $id): User|Domain
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, $id);

        return $entity;
    }
}

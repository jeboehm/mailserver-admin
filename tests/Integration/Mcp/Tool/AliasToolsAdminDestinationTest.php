<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Tool;

use App\Entity\Alias;
use App\Repository\AliasRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\ManagementToolsTrait;

/**
 * A domain admin must not take over an admin's mail through an alias to the admin's address (follow-up to issue #576).
 */
class AliasToolsAdminDestinationTest extends WebTestCase
{
    use ManagementToolsTrait;

    private const string DESTINATION = 'target@elsewhere.example.net';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();
        $this->seedManagementData();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    public function testDomainAdminCannotCreateAnAliasToAnAdminAddress(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_create', ['name' => 'sales2', 'destination' => self::ADMIN]));
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_create', ['name' => 'sales2', 'destination' => 'admin-shop@example.com']));

        self::assertNull($this->repository()->findOneBy(['name' => 'sales2']));
    }

    public function testDomainAdminCannotRetargetAnAliasToAnAdminAddress(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $this->ownAliasId, 'destination' => self::ADMIN]));

        $this->entityManager()->clear();
        self::assertSame('target@elsewhere.example.net', $this->repository()->find($this->ownAliasId)?->getDestination());
    }

    public function testDomainAdminCannotUpdateOrDeleteAnAliasToAnAdminAddress(): void
    {
        $id = $this->createAliasToAdminAddress();

        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $id, 'destination' => 'other@elsewhere.example.net']));
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $id, 'name' => 'renamed']));
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => $id]));

        $this->entityManager()->clear();
        $alias = $this->repository()->find($id);
        self::assertInstanceOf(Alias::class, $alias);
        self::assertSame('legacy', $alias->getName());
        self::assertSame(self::ADMIN, $alias->getDestination());
    }

    public function testDomainAdminCanStillReadAnAliasToAnAdminAddress(): void
    {
        $id = $this->createAliasToAdminAddress();

        $alias = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_get', ['id' => $id]));
        self::assertSame(self::ADMIN, $alias['destination']);

        $page = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_list'));
        self::assertContains('legacy@example.com', $this->pageColumn($page, 'address'));
    }

    public function testAdminManagesAliasesToAnAdminAddress(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_create', ['name' => 'sales2', 'destination' => self::ADMIN]));
        $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_update', ['id' => $created['id'], 'destination' => 'other@elsewhere.example.net']));
        $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_delete', ['id' => $created['id']]));
    }

    public function testOtherDestinationsAreUnaffected(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_create', ['name' => 'regular', 'destination' => self::DESTINATION]));

        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $created['id'], 'destination' => 'other@elsewhere.example.net']));
        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => $created['id']]));
    }

    private function createAliasToAdminAddress(): int
    {
        $domain = $this->findUser(self::ADMIN)->getDomain();
        self::assertNotNull($domain);

        $alias = $this->newAlias('legacy', $domain);
        $alias->setDestination(self::ADMIN);
        $this->entityManager()->flush();

        return (int) $alias->getId();
    }

    private function repository(): AliasRepository
    {
        return static::getContainer()->get(AliasRepository::class);
    }
}

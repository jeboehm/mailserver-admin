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
 * A domain admin must not take over an admin's mail through an alias on the admin's address (issue #576).
 */
class AliasToolsAdminAddressTest extends WebTestCase
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

    public function testDomainAdminCannotCreateAnAliasOnAnAdminAddress(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_create', ['name' => 'admin', 'destination' => self::DESTINATION]));

        self::assertNull($this->repository()->findOneBy(['name' => 'admin']));
    }

    public function testDomainAdminCannotRenameAnAliasOntoAnAdminAddress(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $this->ownAliasId, 'name' => 'admin']));

        $this->entityManager()->clear();
        self::assertSame('sales', $this->repository()->find($this->ownAliasId)?->getName());
    }

    public function testDomainAdminCannotUpdateOrDeleteAnAliasOnAnAdminAddress(): void
    {
        $id = $this->createAliasOnAdminAddress();

        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $id, 'destination' => 'other@elsewhere.example.net']));
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $id, 'name' => 'renamed']));
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => $id]));

        $this->entityManager()->clear();
        $alias = $this->repository()->find($id);
        self::assertInstanceOf(Alias::class, $alias);
        self::assertSame('admin', $alias->getName());
        self::assertSame(self::DESTINATION, $alias->getDestination());
    }

    public function testDomainAdminCanStillReadAnAliasOnAnAdminAddress(): void
    {
        $id = $this->createAliasOnAdminAddress();

        $alias = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_get', ['id' => $id]));
        self::assertSame('admin@example.com', $alias['address']);

        $page = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_list'));
        self::assertContains('admin@example.com', $this->pageColumn($page, 'address'));
    }

    public function testAdminManagesAliasesOnAnAdminAddress(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_create', ['name' => 'admin', 'destination' => self::DESTINATION]));
        $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_update', ['id' => $created['id'], 'destination' => 'other@elsewhere.example.net']));
        $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_delete', ['id' => $created['id']]));
    }

    public function testAliasesOnOtherUsersAddressesAreUnaffected(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_create', ['name' => 'regular', 'destination' => self::DESTINATION]));

        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $created['id'], 'destination' => 'other@elsewhere.example.net']));
        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => $created['id']]));
    }

    private function createAliasOnAdminAddress(): int
    {
        $domain = $this->findUser(self::ADMIN)->getDomain();
        self::assertNotNull($domain);

        $alias = $this->newAlias('admin', $domain);
        $this->entityManager()->flush();

        return (int) $alias->getId();
    }

    private function repository(): AliasRepository
    {
        return static::getContainer()->get(AliasRepository::class);
    }
}

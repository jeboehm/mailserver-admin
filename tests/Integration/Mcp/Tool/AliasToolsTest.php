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

class AliasToolsTest extends WebTestCase
{
    use ManagementToolsTrait;

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

    public function testAdminListsAliasesOfAllDomains(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_list'));

        self::assertSame(2, $page['total']);
        self::assertSame(['sales@example.com', 'info@foreign.example.org'], $this->pageColumn($page, 'address'));
    }

    public function testDomainAdminListsOnlyTheirDomain(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_list'));

        self::assertSame(1, $page['total']);
        self::assertSame(['sales@example.com'], $this->pageColumn($page, 'address'));
    }

    public function testListIsPaginated(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_list', ['limit' => 1, 'offset' => 1]));

        self::assertSame(2, $page['total']);
        self::assertSame(['info@foreign.example.org'], $this->pageColumn($page, 'address'));
    }

    public function testGetReturnsAnAlias(): void
    {
        self::assertSame([
            'id' => $this->ownAliasId,
            'address' => 'sales@example.com',
            'name' => 'sales',
            'domain' => self::OWN_DOMAIN,
            'destination' => 'target@elsewhere.example.net',
        ], $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_get', ['id' => $this->ownAliasId])));
    }

    public function testAdminGetsAnAliasOfAnyDomain(): void
    {
        $alias = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_get', ['id' => $this->foreignAliasId]));

        self::assertSame(self::FOREIGN_DOMAIN, $alias['domain']);
    }

    public function testForeignAndMissingAliasesAnswerTheSame(): void
    {
        $foreign = $this->call(self::DOMAIN_ADMIN, 'alias_get', ['id' => $this->foreignAliasId]);
        $missing = $this->call(self::DOMAIN_ADMIN, 'alias_get', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);
    }

    public function testDomainAdminCreatesAnAliasInTheirDomainByDefault(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_create', [
            'name' => 'fresh',
            'destination' => 'target@elsewhere.example.net',
        ]));

        self::assertSame('fresh@example.com', $created['address']);
        self::assertInstanceOf(Alias::class, $this->repository()->find($created['id']));
    }

    public function testEmptyNameCreatesACatchAll(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_create', [
            'name' => '',
            'destination' => 'target@elsewhere.example.net',
        ]));

        self::assertSame('@example.com', $created['address']);
    }

    public function testDomainAdminCannotChooseAnotherDomain(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'alias_create', [
            'name' => 'fresh',
            'destination' => 'target@elsewhere.example.net',
            'domain' => self::FOREIGN_DOMAIN,
        ]));

        self::assertNull($this->repository()->findOneBy(['name' => 'fresh']));
    }

    public function testAdminChoosesAnyDomain(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_create', [
            'name' => 'fresh',
            'destination' => 'target@elsewhere.example.net',
            'domain' => self::FOREIGN_DOMAIN,
        ]));

        self::assertSame(self::FOREIGN_DOMAIN, $created['domain']);
    }

    public function testCreateValidatesLikeTheForm(): void
    {
        $this->assertToolRejectedField($this->call(self::ADMIN, 'alias_create', ['name' => 'fresh', 'destination' => 'not-an-address']), 'destination');
        $this->assertToolRejectedField($this->call(self::ADMIN, 'alias_create', ['name' => 'Not Valid', 'destination' => 'target@elsewhere.example.net']), 'name');
    }

    public function testCreateRejectsADuplicate(): void
    {
        $result = $this->call(self::DOMAIN_ADMIN, 'alias_create', ['name' => 'sales', 'destination' => 'target@elsewhere.example.net']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('already', $result['content'][0]['text']);
    }

    public function testUpdateChangesOnlyWhatIsPassed(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_update', [
            'id' => $this->ownAliasId,
            'destination' => 'new@elsewhere.example.net',
        ]));

        self::assertSame('sales', $updated['name']);
        self::assertSame('new@elsewhere.example.net', $updated['destination']);
    }

    public function testUpdateRenames(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $this->ownAliasId, 'name' => 'renamed']));

        self::assertSame('renamed@example.com', $updated['address']);
    }

    public function testUpdateValidatesAndKeepsTheOldValues(): void
    {
        $this->assertToolRejectedField($this->call(self::ADMIN, 'alias_update', ['id' => $this->ownAliasId, 'destination' => 'not-an-address']), 'destination');

        $this->entityManager()->clear();
        $alias = $this->repository()->find($this->ownAliasId);
        self::assertInstanceOf(Alias::class, $alias);
        self::assertSame('target@elsewhere.example.net', $alias->getDestination());
    }

    public function testUpdateCannotMoveAnAliasToAnotherDomain(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_update', [
            'id' => $this->ownAliasId,
            'domain' => self::FOREIGN_DOMAIN,
        ]));

        self::assertSame(self::OWN_DOMAIN, $updated['domain']);
    }

    public function testDomainAdminCannotUpdateAForeignAlias(): void
    {
        $foreign = $this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => $this->foreignAliasId, 'destination' => 'new@elsewhere.example.net']);
        $missing = $this->call(self::DOMAIN_ADMIN, 'alias_update', ['id' => self::MISSING_ID, 'destination' => 'new@elsewhere.example.net']);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);

        $this->entityManager()->clear();
        $alias = $this->repository()->find($this->foreignAliasId);
        self::assertInstanceOf(Alias::class, $alias);
        self::assertSame('target@elsewhere.example.net', $alias->getDestination());
    }

    public function testDeleteRemovesAnAliasOfOwnDomain(): void
    {
        self::assertSame(['deleted' => true], $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => $this->ownAliasId])));
        self::assertNull($this->repository()->find($this->ownAliasId));
    }

    public function testAdminDeletesAnAliasOfAnyDomain(): void
    {
        $this->assertToolSucceeded($this->call(self::ADMIN, 'alias_delete', ['id' => $this->foreignAliasId]));

        self::assertNull($this->repository()->find($this->foreignAliasId));
    }

    public function testForeignAndMissingAliasesCannotBeDeletedAndAnswerTheSame(): void
    {
        $foreign = $this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => $this->foreignAliasId]);
        $missing = $this->call(self::DOMAIN_ADMIN, 'alias_delete', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);
        self::assertInstanceOf(Alias::class, $this->repository()->find($this->foreignAliasId));
    }

    private function repository(): AliasRepository
    {
        return static::getContainer()->get(AliasRepository::class);
    }
}

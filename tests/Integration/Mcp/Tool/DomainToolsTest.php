<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Tool;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\ManagementToolsTrait;

class DomainToolsTest extends WebTestCase
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

    public function testListReturnsDomainsSortedWithTheirSize(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::ADMIN, 'domain_list'));

        self::assertSame(3, $page['total']);
        self::assertSame(
            [self::EMPTY_DOMAIN, self::OWN_DOMAIN, self::FOREIGN_DOMAIN],
            $this->pageColumn($page, 'name'),
        );

        $own = $page['items'][1];
        unset($own['id']);
        self::assertSame(['name' => self::OWN_DOMAIN, 'dkim_enabled' => true, 'user_count' => 4, 'alias_count' => 1], $own);
    }

    public function testListIsPaginated(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::ADMIN, 'domain_list', ['limit' => 1, 'offset' => 1]));

        self::assertSame(3, $page['total']);
        self::assertSame(1, $page['limit']);
        self::assertSame(1, $page['offset']);
        self::assertSame([self::OWN_DOMAIN], $this->pageColumn($page, 'name'));
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function pagesOutOfRange(): iterable
    {
        yield 'limit too large' => [['limit' => 101]];
        yield 'limit too small' => [['limit' => 0]];
        yield 'negative offset' => [['offset' => -1]];
    }

    /**
     * @param array<string, int> $arguments
     */
    #[DataProvider('pagesOutOfRange')]
    public function testListRefusesAPageOutOfRange(array $arguments): void
    {
        $result = $this->call(self::ADMIN, 'domain_list', $arguments);

        self::assertTrue($result['isError'], (string) json_encode($result));
        self::assertSame('The limit must be between 1 and 100 and the offset must not be negative.', $result['content'][0]['text']);
    }

    public function testListNeverReturnsPrivateKeys(): void
    {
        $result = $this->call(self::ADMIN, 'domain_list');

        self::assertStringNotContainsString('not-a-real-key', (string) json_encode($result));
        self::assertStringNotContainsStringIgnoringCase('privateKey', (string) json_encode($result));
    }

    public function testCreateStoresTheDomainLowercaseThroughTheEntityListeners(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'domain_create', ['name' => 'New.Example.NET']));

        self::assertSame('new.example.net', $created['name']);
        self::assertSame(0, $created['user_count']);

        $domain = static::getContainer()->get(DomainRepository::class)->find($created['id']);
        self::assertInstanceOf(Domain::class, $domain);
        self::assertSame('new.example.net', $domain->getName());
        self::assertStringContainsString('PRIVATE KEY', $domain->getDkimPrivateKey());
    }

    public function testCreateDropsTheRootDot(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'domain_create', ['name' => 'New.Example.NET.']));

        self::assertSame('new.example.net', $created['name']);
    }

    public function testCreateRejectsAnInvalidName(): void
    {
        $this->assertToolRejectedField($this->call(self::ADMIN, 'domain_create', ['name' => 'not a domain']), 'name');
    }

    public function testCreateRejectsAnExistingName(): void
    {
        $this->assertToolRejectedField($this->call(self::ADMIN, 'domain_create', ['name' => self::FOREIGN_DOMAIN]), 'name');
    }

    public function testDeleteRemovesAnEmptyDomain(): void
    {
        $id = $this->domainId(self::EMPTY_DOMAIN);

        self::assertSame(['deleted' => true], $this->assertToolSucceeded($this->call(self::ADMIN, 'domain_delete', ['id' => $id])));
        self::assertNull(static::getContainer()->get(DomainRepository::class)->find($id));
    }

    public function testDeleteRefusesADomainThatStillHasUsers(): void
    {
        $id = $this->domainId(self::FOREIGN_DOMAIN);

        $this->assertToolDenied($this->call(self::ADMIN, 'domain_delete', ['id' => $id]));

        self::assertInstanceOf(Domain::class, static::getContainer()->get(DomainRepository::class)->find($id));
    }

    public function testDeleteOfAMissingDomainAnswersLikeARefusal(): void
    {
        $this->assertToolDenied($this->call(self::ADMIN, 'domain_delete', ['id' => self::MISSING_ID]));
    }

    private function domainId(string $name): int
    {
        $domain = static::getContainer()->get(DomainRepository::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Domain::class, $domain);

        return (int) $domain->getId();
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Tool;

use App\Service\ApiToken\ApiTokenScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\ManagementToolsTrait;

/**
 * The scope table and role matrix of the specification, tool by tool. What a role may do with a
 * particular object is covered by the test of each tool group.
 */
class ManagementToolAccessTest extends WebTestCase
{
    use ManagementToolsTrait;

    private const array ADMIN_ONLY = [self::DOMAIN_ADMIN, self::REGULAR_USER];
    private const array DOMAIN_ADMIN_AND_UP = [self::REGULAR_USER];

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

    /**
     * @return iterable<string, array{string, ApiTokenScope, list<string>}> tool, scope, roles without access
     */
    public static function tools(): iterable
    {
        yield 'domain_list' => ['domain_list', ApiTokenScope::ManageRead, self::ADMIN_ONLY];
        yield 'domain_create' => ['domain_create', ApiTokenScope::ManageWrite, self::ADMIN_ONLY];
        yield 'domain_delete' => ['domain_delete', ApiTokenScope::ManageWrite, self::ADMIN_ONLY];
        yield 'user_list' => ['user_list', ApiTokenScope::ManageRead, self::DOMAIN_ADMIN_AND_UP];
        yield 'user_get' => ['user_get', ApiTokenScope::ManageRead, self::DOMAIN_ADMIN_AND_UP];
        yield 'user_create' => ['user_create', ApiTokenScope::ManageWrite, self::DOMAIN_ADMIN_AND_UP];
        yield 'user_update' => ['user_update', ApiTokenScope::ManageWrite, self::DOMAIN_ADMIN_AND_UP];
        yield 'user_delete' => ['user_delete', ApiTokenScope::ManageWrite, self::DOMAIN_ADMIN_AND_UP];
        yield 'alias_list' => ['alias_list', ApiTokenScope::ManageRead, self::DOMAIN_ADMIN_AND_UP];
        yield 'alias_get' => ['alias_get', ApiTokenScope::ManageRead, self::DOMAIN_ADMIN_AND_UP];
        yield 'alias_create' => ['alias_create', ApiTokenScope::ManageWrite, self::DOMAIN_ADMIN_AND_UP];
        yield 'alias_update' => ['alias_update', ApiTokenScope::ManageWrite, self::DOMAIN_ADMIN_AND_UP];
        yield 'alias_delete' => ['alias_delete', ApiTokenScope::ManageWrite, self::DOMAIN_ADMIN_AND_UP];
        yield 'fetchmail_list' => ['fetchmail_list', ApiTokenScope::ManageRead, []];
        yield 'fetchmail_get' => ['fetchmail_get', ApiTokenScope::ManageRead, []];
        yield 'fetchmail_create' => ['fetchmail_create', ApiTokenScope::ManageWrite, []];
        yield 'fetchmail_update' => ['fetchmail_update', ApiTokenScope::ManageWrite, []];
        yield 'fetchmail_delete' => ['fetchmail_delete', ApiTokenScope::ManageWrite, []];
    }

    /**
     * @param list<string> $rolesWithoutAccess
     */
    #[DataProvider('tools')]
    public function testToolIsListedAndRefusedWithoutItsScope(string $tool, ApiTokenScope $scope, array $rolesWithoutAccess): void
    {
        $otherScopes = array_values(array_filter(ApiTokenScope::ALL, static fn (ApiTokenScope $other): bool => $other !== $scope));
        $without = $this->storeToken($this->findUser(self::ADMIN), $otherScopes);
        $withoutSession = $this->initialiseSession($without);

        self::assertNotContains($tool, $this->listTools($without, $withoutSession));
        $this->assertToolDenied($this->callTool($without, $withoutSession, $tool, $this->argumentsFor($tool)));

        $with = $this->storeToken($this->findUser(self::ADMIN), [$scope]);

        self::assertContains($tool, $this->listTools($with, $this->initialiseSession($with)));
    }

    /**
     * @param list<string> $rolesWithoutAccess
     */
    #[DataProvider('tools')]
    public function testToolIsHiddenAndRefusedForRolesBelowIt(string $tool, ApiTokenScope $scope, array $rolesWithoutAccess): void
    {
        foreach ([self::ADMIN, self::DOMAIN_ADMIN, self::REGULAR_USER] as $role) {
            $secret = $this->storeToken($this->findUser($role));
            $sessionId = $this->initialiseSession($secret);

            if (\in_array($role, $rolesWithoutAccess, true)) {
                self::assertNotContains($tool, $this->listTools($secret, $sessionId), $role);
                $this->assertToolDenied($this->callTool($secret, $sessionId, $tool, $this->argumentsFor($tool)));
            } else {
                self::assertContains($tool, $this->listTools($secret, $sessionId), $role);
            }
        }
    }

    /**
     * Valid for the schema of the tool, because the SDK checks that before the gate runs.
     *
     * @return array<string, mixed>
     */
    private function argumentsFor(string $tool): array
    {
        return match ($tool) {
            'domain_create' => ['name' => 'matrix.example.net'],
            'user_create' => ['name' => 'matrix', 'password' => self::STRONG_PASSWORD],
            'alias_create' => ['name' => 'matrix', 'destination' => 'target@elsewhere.example.net'],
            'fetchmail_create' => ['host' => 'mail.example.net', 'username' => 'matrix', 'password' => 'secret'],
            'domain_list', 'user_list', 'alias_list', 'fetchmail_list' => [],
            default => ['id' => self::MISSING_ID],
        };
    }
}

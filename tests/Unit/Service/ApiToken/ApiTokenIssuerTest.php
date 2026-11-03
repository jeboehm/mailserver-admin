<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\ApiToken;

use App\Entity\User;
use App\Service\ApiToken\ApiTokenIssuer;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiTokenIssuerTest extends TestCase
{
    public function testStoresOnlyTheHashOfTheSecret(): void
    {
        $issued = $this->issuer()->issue(new User(), 'agent', [ApiTokenScope::ManageRead]);

        self::assertSame(hash('sha256', $issued->secret), $issued->token->getSecretHash());
        self::assertStringNotContainsString($issued->secret, serialize([$issued->token->getSecretHash(), $issued->token->getName()]));
    }

    public function testSecretsAreHighEntropyAndUsableAsBearerTokens(): void
    {
        $first = $this->issuer()->issue(new User(), 'agent', [ApiTokenScope::ManageRead])->secret;
        $second = $this->issuer()->issue(new User(), 'agent', [ApiTokenScope::ManageRead])->secret;

        self::assertNotSame($first, $second);
        self::assertGreaterThanOrEqual(64, \strlen($first));
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9\-._~+\/]+=*$/', $first);
    }

    public function testExpiryDefaultsToTheMaximum(): void
    {
        $token = $this->issuer(maxDays: 30)->issue(new User(), 'agent', [ApiTokenScope::ManageRead])->token;

        self::assertEqualsWithDelta(new \DateTimeImmutable('+30 days')->getTimestamp(), $token->getExpiresAt()->getTimestamp(), 5);
    }

    public function testChosenLifetimeIsUsed(): void
    {
        $token = $this->issuer()->issue(new User(), 'agent', [ApiTokenScope::ManageRead], 7)->token;

        self::assertEqualsWithDelta(new \DateTimeImmutable('+7 days')->getTimestamp(), $token->getExpiresAt()->getTimestamp(), 5);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLifetimes(): iterable
    {
        yield 'above the maximum' => [91];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidLifetimes')]
    public function testLifetimeOutsideTheAllowedRangeIsRefused(int $days): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->issuer()->issue(new User(), 'agent', [ApiTokenScope::ManageRead], $days);
    }

    public function testTokenWithoutScopesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->issuer()->issue(new User(), 'agent', []);
    }

    private function issuer(int $maxDays = 90): ApiTokenIssuer
    {
        return new ApiTokenIssuer($this->createStub(EntityManagerInterface::class), $maxDays);
    }
}

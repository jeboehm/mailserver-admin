<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\ApiToken;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ApiTokenIssuer
{
    private const string SECRET_PREFIX = 'msa_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(int:MCP_TOKEN_MAX_LIFETIME_DAYS)%')]
        private int $maxLifetimeDays,
    ) {
    }

    public function maxLifetimeDays(): int
    {
        return $this->maxLifetimeDays;
    }

    /**
     * @param list<ApiTokenScope> $scopes
     * @param int|null            $lifetimeDays  defaults to the maximum
     * @param string|null         $senderAddress used by SenderMode::Fixed only, and must be one the caller checked the
     *                                           owner owns
     *
     * @throws \InvalidArgumentException
     */
    public function issue(
        User $owner,
        string $name,
        array $scopes,
        ?int $lifetimeDays = null,
        SenderMode $senderMode = SenderMode::Primary,
        ?string $senderAddress = null,
    ): IssuedApiToken {
        $lifetimeDays ??= $this->maxLifetimeDays;

        if ($lifetimeDays < 1 || $lifetimeDays > $this->maxLifetimeDays) {
            throw new \InvalidArgumentException(\sprintf('The lifetime of a token must be between 1 and %d days.', $this->maxLifetimeDays));
        }

        if ([] === $scopes) {
            throw new \InvalidArgumentException('A token needs at least one scope.');
        }

        // A token that neither sends nor drafts keeps the default, so it gains nothing if it ever does.
        if (!array_any($scopes, static fn (ApiTokenScope $scope): bool => \in_array($scope, ApiTokenScope::SENDER_BOUND, true))) {
            $senderMode = SenderMode::Primary;
        }

        if (SenderMode::Fixed !== $senderMode) {
            $senderAddress = null;
        }

        $secret = self::SECRET_PREFIX . bin2hex(random_bytes(32));
        $token = new ApiToken(
            $owner,
            $name,
            ApiToken::hashSecret($secret),
            $scopes,
            new \DateTimeImmutable(\sprintf('+%d days', $lifetimeDays)),
            $senderMode,
            $senderAddress,
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return new IssuedApiToken($token, $secret);
    }
}

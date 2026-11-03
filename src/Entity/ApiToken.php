<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A personal credential for the MCP endpoint. Only the hash of its secret is stored.
 *
 * A revoked token stays revoked: nothing clears revokedAt, so re-enabling a user does not bring
 * their old tokens back.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    private const int LAST_USED_RESOLUTION_SECONDS = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $secretHash;

    /** @var list<string> */
    #[ORM\Column(type: Types::SIMPLE_ARRAY)]
    private array $scopes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param list<ApiTokenScope> $scopes
     */
    public function __construct(User $owner, string $name, string $secretHash, array $scopes, \DateTimeImmutable $expiresAt)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->secretHash = $secretHash;
        $this->scopes = array_map(static fn (ApiTokenScope $scope): string => $scope->value, $scopes);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    /**
     * @return list<ApiTokenScope>
     */
    public function getScopes(): array
    {
        // A value no longer known grants nothing, rather than failing every request of the token.
        return array_values(array_filter(array_map(ApiTokenScope::tryFrom(...), $this->scopes)));
    }

    public function getScopeNames(): string
    {
        return implode(', ', $this->scopes);
    }

    public function hasScope(ApiTokenScope $scope): bool
    {
        return \in_array($scope, $this->getScopes(), true);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    /**
     * @return bool whether anything changed, as writing on every request would turn each read into a
     *              database write
     */
    public function markUsed(\DateTimeImmutable $at): bool
    {
        if (null !== $this->lastUsedAt && $at->getTimestamp() - $this->lastUsedAt->getTimestamp() < self::LAST_USED_RESOLUTION_SECONDS) {
            return false;
        }

        $this->lastUsedAt = $at;

        return true;
    }
}

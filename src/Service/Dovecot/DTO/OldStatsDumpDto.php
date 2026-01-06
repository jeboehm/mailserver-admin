<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot\DTO;

/**
 * Represents a single stats dump sample from Dovecot's oldStatsDump command.
 */
final readonly class OldStatsDumpDto
{
    /**
     * @param string                  $type            The stats type (e.g., "global")
     * @param \DateTimeImmutable      $fetchedAt       When this sample was fetched
     * @param float|null              $lastUpdateSeconds The last_update value from Dovecot (seconds since epoch)
     * @param int|null                $resetTimestamp  The reset_timestamp value (seconds since epoch)
     * @param array<string, int|float> $counters       All parsed numeric counters from the response
     */
    public function __construct(
        public string $type,
        public \DateTimeImmutable $fetchedAt,
        public ?float $lastUpdateSeconds,
        public ?int $resetTimestamp,
        public array $counters,
    ) {
    }

    public function getCounter(string $name): int|float|null
    {
        return $this->counters[$name] ?? null;
    }

    public function getCounterAsInt(string $name): ?int
    {
        $value = $this->getCounter($name);

        return null !== $value ? (int) $value : null;
    }

    public function getCounterAsFloat(string $name): ?float
    {
        $value = $this->getCounter($name);

        return null !== $value ? (float) $value : null;
    }

    public function hasCounter(string $name): bool
    {
        return isset($this->counters[$name]);
    }

    public function getResetDateTime(): ?\DateTimeImmutable
    {
        if (null === $this->resetTimestamp) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($this->resetTimestamp);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'fetchedAt' => $this->fetchedAt->format(\DateTimeInterface::ATOM),
            'lastUpdateSeconds' => $this->lastUpdateSeconds,
            'resetTimestamp' => $this->resetTimestamp,
            'counters' => $this->counters,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            fetchedAt: new \DateTimeImmutable($data['fetchedAt']),
            lastUpdateSeconds: $data['lastUpdateSeconds'],
            resetTimestamp: $data['resetTimestamp'],
            counters: $data['counters'],
        );
    }
}

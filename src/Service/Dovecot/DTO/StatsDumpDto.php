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
 * Represents a single stats dump sample from Dovecot's statsDump command.
 */
final readonly class StatsDumpDto
{
    /**
     * @param \DateTimeImmutable       $fetchedAt When this sample was fetched
     * @param array<string, int|float> $counters  All parsed numeric counters from the response
     */
    public function __construct(
        public \DateTimeImmutable $fetchedAt,
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fetchedAt' => $this->fetchedAt->format(\DateTimeInterface::ATOM),
            'counters' => $this->counters,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fetchedAt: new \DateTimeImmutable($data['fetchedAt']),
            counters: $data['counters'],
        );
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd\DTO;

/**
 * Represents action distribution data for pie/doughnut charts.
 */
final readonly class ActionDistributionDto
{
    /**
     * @param array<string, int> $actions Action name => count
     */
    public function __construct(
        public array $actions,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->actions;
    }

    public function getTotal(): int
    {
        return (int) array_sum($this->actions);
    }

    /**
     * @return list<string>
     */
    public function getLabels(): array
    {
        return array_keys($this->actions);
    }

    /**
     * @return list<int>
     */
    public function getValues(): array
    {
        return array_values($this->actions);
    }
}

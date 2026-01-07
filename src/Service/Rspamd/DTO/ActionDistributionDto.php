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
     * @param array<string, int>    $actions Action name => count
     * @param array<string, string> $colors  Action name => color (hex or rgba)
     */
    public function __construct(
        public array $actions,
        public array $colors = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
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

    /**
     * Get color for a specific action label.
     */
    public function getColor(string $label): ?string
    {
        return $this->colors[$label] ?? null;
    }

    /**
     * Get colors in the same order as labels.
     *
     * @return list<string|null>
     */
    public function getColors(): array
    {
        $result = [];
        foreach ($this->getLabels() as $label) {
            $result[] = $this->getColor($label);
        }

        return $result;
    }
}

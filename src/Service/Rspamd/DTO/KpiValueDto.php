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
 * Represents a single KPI value with optional formatting.
 */
final readonly class KpiValueDto
{
    public function __construct(
        public string $label,
        public int|float|null $value,
        public ?string $unit = null,
        public ?string $icon = null,
        public ?string $trend = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->value;
    }

    public function getFormattedValue(): string
    {
        if (null === $this->value) {
            return 'n/a';
        }

        if (\is_float($this->value)) {
            return number_format($this->value, 2) . ($this->unit ? ' ' . $this->unit : '');
        }

        return number_format($this->value) . ($this->unit ? ' ' . $this->unit : '');
    }
}

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
 * Represents time series data for charting.
 */
final readonly class TimeSeriesDto
{
    public const string TYPE_HOURLY = 'hourly';
    public const string TYPE_DAILY = 'daily';
    public const string TYPE_WEEKLY = 'weekly';
    public const string TYPE_MONTHLY = 'monthly';

    public const array VALID_TYPES = [
        self::TYPE_HOURLY,
        self::TYPE_DAILY,
        self::TYPE_WEEKLY,
        self::TYPE_MONTHLY,
    ];

    /**
     * @param list<string>    $labels
     * @param array<string, list<int|float>> $datasets Dataset name => values
     */
    public function __construct(
        public string $type,
        public array $labels,
        public array $datasets,
    ) {
    }

    public static function empty(string $type): self
    {
        return new self($type, [], []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->labels || [] === $this->datasets;
    }

    public static function isValidType(string $type): bool
    {
        return \in_array($type, self::VALID_TYPES, true);
    }
}

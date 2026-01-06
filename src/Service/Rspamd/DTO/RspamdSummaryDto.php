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
 * Represents the complete Rspamd summary for the dashboard.
 */
final readonly class RspamdSummaryDto
{
    /**
     * @param array<string, KpiValueDto> $kpis
     */
    public function __construct(
        public HealthDto $health,
        public array $kpis,
        public ActionDistributionDto $actionDistribution,
        public \DateTimeImmutable $generatedAt,
    ) {
    }
}

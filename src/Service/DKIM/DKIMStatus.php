<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

class DKIMStatus
{
    /**
     * @param list<string> $issues human-readable reasons why the published record is not valid
     */
    public function __construct(
        private readonly bool $dkimEnabled,
        private readonly bool $dkimRecordFound,
        private readonly bool $dkimRecordValid,
        private readonly string $currentRecord,
        private readonly string $expectedRecord = '',
        private readonly array $issues = [],
    ) {
    }

    public function isDkimEnabled(): bool
    {
        return $this->dkimEnabled;
    }

    public function isDkimRecordFound(): bool
    {
        return $this->dkimRecordFound;
    }

    public function isDkimRecordValid(): bool
    {
        return $this->dkimRecordValid;
    }

    public function getCurrentRecord(): string
    {
        return $this->currentRecord;
    }

    public function getExpectedRecord(): string
    {
        return $this->expectedRecord;
    }

    /**
     * @return list<string>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }
}

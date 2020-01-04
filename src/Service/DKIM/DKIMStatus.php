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
    private bool $dkimEnabled;
    private bool $dkimRecordFound;
    private bool $dkimRecordValid;
    private string $currentRecord;

    public function __construct(bool $dkimEnabled, bool $dkimRecordFound, bool $dkimRecordValid, string $currentRecord)
    {
        $this->dkimEnabled = $dkimEnabled;
        $this->dkimRecordFound = $dkimRecordFound;
        $this->dkimRecordValid = $dkimRecordValid;
        $this->currentRecord = $currentRecord;
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
}

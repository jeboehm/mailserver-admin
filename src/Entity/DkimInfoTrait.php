<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Service\DKIM\DKIMStatus;

trait DkimInfoTrait
{
    private DKIMStatus $dkimStatus;
    private string $expectedDnsRecord;
    private string $currentDnsRecord;

    public function getDkimStatus(): DKIMStatus
    {
        return $this->dkimStatus;
    }

    public function setDkimStatus(DKIMStatus $dkimStatus): void
    {
        $this->dkimStatus = $dkimStatus;
    }

    public function getExpectedDnsRecord(): string
    {
        return $this->expectedDnsRecord;
    }

    public function setExpectedDnsRecord(string $expectedDnsRecord): void
    {
        $this->expectedDnsRecord = $expectedDnsRecord;
    }

    public function getCurrentDnsRecord(): string
    {
        return $this->currentDnsRecord;
    }

    public function setCurrentDnsRecord(string $currentDnsRecord): void
    {
        $this->currentDnsRecord = $currentDnsRecord;
    }
}

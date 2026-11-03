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
    private ?DKIMStatus $dkimStatus = null;
    private ?string $expectedDnsRecord = null;
    private ?string $currentDnsRecord = null;
    private ?\Closure $dkimRuntimeDataLoader = null;

    /**
     * The runtime data needs a DNS lookup, which can take seconds, so it is only fetched when first
     * read. Most code that loads a domain only wants its name.
     *
     * @param \Closure(self): void $loader fills in the runtime data through the setters
     */
    public function deferDkimRuntimeData(\Closure $loader): void
    {
        $this->dkimStatus = null;
        $this->expectedDnsRecord = null;
        $this->currentDnsRecord = null;
        $this->dkimRuntimeDataLoader = $loader;
    }

    public function getDkimStatus(): ?DKIMStatus
    {
        $this->loadDkimRuntimeData();

        return $this->dkimStatus;
    }

    public function setDkimStatus(DKIMStatus $dkimStatus): void
    {
        $this->dkimStatus = $dkimStatus;
    }

    public function getExpectedDnsRecord(): ?string
    {
        $this->loadDkimRuntimeData();

        return $this->expectedDnsRecord;
    }

    public function setExpectedDnsRecord(string $expectedDnsRecord): void
    {
        $this->expectedDnsRecord = $expectedDnsRecord;
    }

    public function getCurrentDnsRecord(): ?string
    {
        $this->loadDkimRuntimeData();

        return $this->currentDnsRecord;
    }

    public function setCurrentDnsRecord(string $currentDnsRecord): void
    {
        $this->currentDnsRecord = $currentDnsRecord;
    }

    private function loadDkimRuntimeData(): void
    {
        if (null === $this->dkimRuntimeDataLoader) {
            return;
        }

        $loader = $this->dkimRuntimeDataLoader;
        $this->dkimRuntimeDataLoader = null;
        $loader($this);
    }
}

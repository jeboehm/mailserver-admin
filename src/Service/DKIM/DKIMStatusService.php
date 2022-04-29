<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

use App\Entity\Domain;
use App\Exception\DKIM\DomainKeyNotFoundException;

class DKIMStatusService
{
    public function __construct(private DomainKeyReaderService $domainKeyReaderService, private FormatterService $formatterService, private KeyGenerationService $keyGenerationService)
    {
    }

    public function getStatus(Domain $domain): DKIMStatus
    {
        if (empty($domain->getDkimPrivateKey()) || empty($domain->getDkimSelector())) {
            return new DKIMStatus($domain->getDkimEnabled(), false, false, '');
        }

        try {
            $key = $this->domainKeyReaderService->getDomainKey($domain->getName(), $domain->getDkimSelector());
            $parts = [];

            foreach ($key as $name => $value) {
                $parts[] = sprintf('%s=%s', $name, $value);
            }

            $dnsRecord = \implode('\; ', $parts);
            $generatedRecord = $this->formatterService->getTXTRecord(
                $this->keyGenerationService->extractPublicKey($domain->getDkimPrivateKey()),
                KeyGenerationService::DIGEST_ALGORITHM
            );

            if ($dnsRecord === $generatedRecord) {
                return new DKIMStatus($domain->getDkimEnabled(), true, true, $dnsRecord);
            }

            return new DKIMStatus($domain->getDkimEnabled(), true, false, $dnsRecord);
        } catch (DomainKeyNotFoundException) {
            return new DKIMStatus($domain->getDkimEnabled(), false, false, '');
        }
    }
}

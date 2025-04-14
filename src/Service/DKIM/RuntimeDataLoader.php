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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: Domain::class)]
readonly class RuntimeDataLoader
{
    public function __construct(
        private FormatterService $formatterService,
        private DKIMStatusService $dkimStatusService,
        private KeyGenerationService $keyGenerationService,
    ) {
    }

    public function postLoad(Domain $domain): void
    {
        $status = $this->dkimStatusService->getStatus($domain);
        $domain->setDkimStatus($status);

        if ('' !== $domain->getDkimPrivateKey()) {
            $expectedRecord = $this->formatterService->getTXTRecord(
                $this->keyGenerationService->extractPublicKey($domain->getDkimPrivateKey()),
                KeyGenerationService::DIGEST_ALGORITHM
            );
            $domain->setExpectedDnsRecord($expectedRecord);
            $domain->setCurrentDnsRecord($domain->getDkimStatus()?->getCurrentRecord() ?? '');
        }
    }
}

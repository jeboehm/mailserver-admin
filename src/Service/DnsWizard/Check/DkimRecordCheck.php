<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard\Check;

use App\Entity\Domain;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;

readonly class DkimRecordCheck implements DnsCheckInterface
{
    public function __construct(private DKIMStatusService $statusService)
    {
    }

    public static function getDefaultPriority(): int
    {
        return 50;
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps, array $expectedAll): array
    {
        return [];
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateDomain(string $mailname, array $expectedAll, Domain $domain): array
    {
        if (!$domain->getDkimEnabled()) {
            return [];
        }

        $status = $this->statusService->getStatus($domain);
        $message = 'DKIM record valid';

        if (!$status->isDkimRecordFound()) {
            $message = 'DKIM record missing or empty';
        } elseif (!$status->isDkimRecordValid()) {
            $message = 'DKIM record mismatch';
        }

        return [
            new DnsWizardRow(
                scope: Scopes::SCOPE_DOMAIN,
                subject: \sprintf('%s._domainkey.%s', $domain->getDkimSelector(), $domain),
                recordType: 'TXT',
                expectedValues: ['Valid DKIM record'],
                actualValues: [$status->getCurrentRecord()],
                status: $status->isDkimRecordValid() ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
                message: $message,
            ),
        ];
    }
}

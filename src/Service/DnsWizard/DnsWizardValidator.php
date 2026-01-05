<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

use App\Entity\Domain;
use App\Service\DnsWizard\Check\DnsCheckInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

readonly class DnsWizardValidator
{
    /**
     * @param iterable<DnsCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator(DnsCheckInterface::TAG_NAME)]
        private iterable $checks
    ) {
    }

    /**
     * @param Domain[] $domains
     *
     * @return array{mailHost: DnsWizardRow[], domains: array<string, DnsWizardRow[]>}
     */
    public function validate(string $mailname, ExpectedHostIps $expectedHostIps, array $domains): array
    {
        $mailnameNormalized = $this->normalizeHostname($mailname);
        $expectedAll = $expectedHostIps->all();

        $mailHostRows = [];

        foreach ($this->checks as $check) {
            $rows = $check->validateMailHost($mailnameNormalized, $expectedHostIps, $expectedAll);
            $mailHostRows = [...$mailHostRows, ...$rows];
        }

        $domainRows = [];

        foreach ($domains as $domain) {
            $name = $domain->getName();
            $domainRows[$name] = [];

            foreach ($this->checks as $check) {
                $rows = $check->validateDomain($mailnameNormalized, $expectedAll, $domain);
                $domainRows[$name] = [...$domainRows[$name], ...$rows];
            }
        }

        return [
            'mailHost' => $mailHostRows,
            'domains' => $domainRows,
        ];
    }

    private function normalizeHostname(string $host): string
    {
        return \rtrim(\strtolower($host), '.');
    }
}

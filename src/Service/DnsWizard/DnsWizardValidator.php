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
        $mailHostRows = $this->validateMailHost($mailname, $expectedHostIps);
        $domainRows = [];

        foreach ($domains as $domain) {
            $domainRows[$domain->getName()] = $this->validateDomain($mailname, $expectedHostIps, $domain);
        }

        return [
            'mailHost' => $mailHostRows,
            'domains' => $domainRows,
        ];
    }

    /**
     * @return list<DnsWizardRow>
     */
    public function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps): array
    {
        $rows = [];

        foreach ($this->checks as $check) {
            $rows = [...$rows, ...$check->validateMailHost($this->normalizeHostname($mailname), $expectedHostIps, $expectedHostIps->all())];
        }

        return $rows;
    }

    /**
     * @return list<DnsWizardRow>
     */
    public function validateDomain(string $mailname, ExpectedHostIps $expectedHostIps, Domain $domain): array
    {
        $rows = [];

        foreach ($this->checks as $check) {
            $rows = [...$rows, ...$check->validateDomain($this->normalizeHostname($mailname), $expectedHostIps->all(), $domain)];
        }

        return $rows;
    }

    private function normalizeHostname(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }
}

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

/**
 * Runs DnsWizardValidator for as long as a time budget allows and reports how far it got, for callers
 * that must answer before PHP's time limit ends the request.
 */
final readonly class TimeBoxedDnsValidation
{
    public function __construct(
        private DnsWizardValidator $validator,
        private DeadlineDnsLookup $lookup,
    ) {
    }

    /**
     * A caller continuing an earlier run passes $withMailHost false. The first domain then has the
     * whole budget, and if that is not enough it is passed over: it would fail the same way on every
     * retry, and the caller could never get past it.
     *
     * @param list<Domain> $domains
     */
    public function run(string $mailname, ExpectedHostIps $expectedHostIps, array $domains, bool $withMailHost, float $budgetSeconds): TimeBoxedDnsValidationResult
    {
        $this->lookup->limitTo($budgetSeconds);

        try {
            $mailHost = null;

            if ($withMailHost) {
                try {
                    $mailHost = $this->validator->validateMailHost($mailname, $expectedHostIps);
                } catch (DnsDeadlineExceededException) {
                    return new TimeBoxedDnsValidationResult(null, true, [], [], 0);
                }
            }

            $finished = [];

            foreach ($domains as $index => $domain) {
                try {
                    $finished[$domain->getName()] = $this->validator->validateDomain($mailname, $expectedHostIps, $domain);
                } catch (DnsDeadlineExceededException) {
                    if (0 === $index && !$withMailHost) {
                        return new TimeBoxedDnsValidationResult(null, false, [], [$domain->getName()], 1);
                    }

                    return new TimeBoxedDnsValidationResult($mailHost, false, $finished, [], $index);
                }
            }

            return new TimeBoxedDnsValidationResult($mailHost, false, $finished, [], \count($domains));
        } finally {
            $this->lookup->removeLimit();
        }
    }
}

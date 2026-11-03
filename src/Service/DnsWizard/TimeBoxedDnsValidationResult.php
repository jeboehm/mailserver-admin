<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

final readonly class TimeBoxedDnsValidationResult
{
    /**
     * @param list<DnsWizardRow>|null           $mailHost null if it was not checked
     * @param array<string, list<DnsWizardRow>> $domains  the domains that finished
     * @param list<string>                      $timedOut domains passed over, which says nothing about their records
     * @param int                               $consumed how many of the given domains the next call can skip
     */
    public function __construct(
        public ?array $mailHost,
        public bool $mailHostTimedOut,
        public array $domains,
        public array $timedOut,
        public int $consumed,
    ) {
    }
}

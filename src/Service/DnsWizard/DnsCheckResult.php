<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

final readonly class DnsCheckResult
{
    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    public function __construct(
        public string $scope,
        public string $subject,
        public string $recordType,
        public array $expected,
        public array $actual,
        public DnsCheckStatus $status,
        public string $message,
    ) {
    }
}


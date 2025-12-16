<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

final readonly class DnsWizardRow
{
    /**
     * @param list<string> $expectedValues
     * @param list<string> $actualValues
     */
    public function __construct(
        public string $scope,
        public string $subject,
        public string $recordType,
        public array $expectedValues,
        public array $actualValues,
        public DnsWizardStatus $status,
        public string $message,
    ) {
    }
}

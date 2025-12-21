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
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\ExpectedHostIps;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(self::TAG_NAME)]
interface DnsCheckInterface
{
    public const string TAG_NAME = 'app.dns_wizard.check';

    public static function getDefaultPriority(): int;

    /**
     * @return DnsWizardRow[]
     */
    public function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps, array $expectedAll): array;

    /**
     * @param string[] $expectedAll
     *
     * @return DnsWizardRow[]
     */
    public function validateDomain(string $mailname, array $expectedAll, Domain $domain): array;
}

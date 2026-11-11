<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * An address as a caller passes it to a mail tool: name@example.com, without a display name.
 */
final class PlainAddress
{
    /** For messages that tell the caller what to pass. */
    public const string DESCRIPTION = 'a plain address such as name@example.com';

    /**
     * @return Address|null null for anything that is not a valid address
     */
    public static function parse(string $value): ?Address
    {
        try {
            return new Address($value);
        } catch (InvalidArgumentException|RfcComplianceException) {
            return null;
        }
    }
}

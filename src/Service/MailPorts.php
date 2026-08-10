<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

/**
 * Ports served by the mail server.
 *
 * Single source of truth for the client configuration endpoints (autoconfig,
 * autodiscover, mobileconfig) and the SRV record hints of the DNS wizard.
 * Service names and their ports follow RFC 6186 / RFC 8314.
 */
final readonly class MailPorts
{
    /** IMAP with STARTTLS. */
    public const int IMAP = 143;

    /** IMAP with implicit TLS. */
    public const int IMAPS = 993;

    /** Message submission with STARTTLS. */
    public const int SUBMISSION = 587;

    /** Message submission with implicit TLS. */
    public const int SUBMISSIONS = 465;

    /** HTTPS endpoint serving the Microsoft autodiscover XML. */
    public const int AUTODISCOVER = 443;

    private function __construct()
    {
    }
}

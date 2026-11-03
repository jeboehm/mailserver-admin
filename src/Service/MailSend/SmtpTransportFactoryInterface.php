<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Service\MailboxCredential\ShortLivedCredential;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

interface SmtpTransportFactoryInterface
{
    /**
     * @return EsmtpTransport not connected yet, logs in with the credential when started
     */
    public function create(ShortLivedCredential $credential): EsmtpTransport;
}

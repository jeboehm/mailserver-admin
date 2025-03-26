<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\FetchmailAccount;

use App\Entity\FetchmailAccount;

class AccountData
{
    public int $id;
    public string $host;
    public string $protocol;
    public int $port;
    public string $username;
    public string $password;
    public string $user;
    public bool $ssl;
    public bool $verifySsl;

    public static function fromFetchmailAccount(FetchmailAccount $fetchmailAccount): self
    {
        $accountData = new self();
        $accountData->id = $fetchmailAccount->getId();
        $accountData->host = $fetchmailAccount->getHost();
        $accountData->protocol = $fetchmailAccount->getProtocol();
        $accountData->port = $fetchmailAccount->getPort();
        $accountData->username = $fetchmailAccount->getUsername();
        $accountData->password = $fetchmailAccount->getPassword();
        $accountData->user = $fetchmailAccount->getUser()?->__toString();
        $accountData->ssl = $fetchmailAccount->isSsl();
        $accountData->verifySsl = $fetchmailAccount->isVerifySsl();

        return $accountData;
    }
}

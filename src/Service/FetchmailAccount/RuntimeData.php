<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\FetchmailAccount;

use Symfony\Component\Serializer\Attribute\Context;

class RuntimeData
{
    public bool $isSuccess;

    #[Context(['datetime_format' => 'Y-m-d\TH:i:s+'])]
    public \DateTimeInterface $lastRun;
    public string $lastLog;
}

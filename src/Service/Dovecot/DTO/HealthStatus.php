<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot\DTO;

enum HealthStatus: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
}

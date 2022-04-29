<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

class KeyPair
{
    public function __construct(private string $public, private string $private)
    {
    }

    public function getPublic(): string
    {
        return $this->public;
    }

    public function getPrivate(): string
    {
        return $this->private;
    }
}

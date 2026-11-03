<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Support\Str;

final class ListedMailboxes
{
    /**
     * What the server's LIST says, with decoded names: * LIST (attributes) "delimiter" name.
     *
     * @return array<string, list<string>> the attributes by mailbox name
     */
    public static function of(ImapConnection $connection): array
    {
        $listed = [];

        foreach ($connection->list() as $response) {
            $attributes = $response->tokenAt(2);
            $name = $response->tokenAt(4);

            if ($attributes instanceof ListData && $name instanceof Token) {
                $listed[mb_scrub(Str::fromImapUtf7($name->value), 'UTF-8')] = array_values($attributes->values());
            }
        }

        return $listed;
    }
}

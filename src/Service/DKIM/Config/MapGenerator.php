<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM\Config;

use App\Entity\Domain;
use Predis\ClientInterface;

readonly class MapGenerator
{
    private const string KEY_HASHMAP = 'dkim_keys';

    public function __construct(private ClientInterface $redis)
    {
    }

    public function generate(Domain ...$domains): void
    {
        $keysDict = [];

        foreach ($domains as $domain) {
            if ($domain->getDkimEnabled()
                && !empty($domain->getDkimPrivateKey())
                && !empty($domain->getDkimSelector())) {
                $dkimDomain = $domain->getDkimSelector() . '.' . $domain->getName();
                $keysDict[$dkimDomain] = $domain->getDkimPrivateKey();
            }
        }

        if ([] !== $keysDict) {
            $this->redis->hmset(self::KEY_HASHMAP, $keysDict);
        }
    }
}

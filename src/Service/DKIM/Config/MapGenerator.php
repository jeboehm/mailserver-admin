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
use Predis\Client;

class MapGenerator
{
    private const string KEY_SELECTOR_MAP = 'dkim_selectors.map';
    private const string KEY_HASHMAP = 'dkim_keys';

    public function __construct(private readonly Client $redis)
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

                $this->redis->hset(self::KEY_SELECTOR_MAP, $domain->getName(), $domain->getDkimSelector());
            } else {
                $this->redis->hdel(self::KEY_SELECTOR_MAP, [$domain->getName()]);
            }
        }

        if ([] !== $keysDict) {
            $this->redis->hmset(self::KEY_HASHMAP, $keysDict);
        }
    }
}

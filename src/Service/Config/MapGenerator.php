<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Config;

use App\Entity\Domain;

class MapGenerator
{
    private const MAP_FILENAME = 'dkim_selectors.map';
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function generate(Domain ...$domains): void
    {
        $map = [];

        foreach ($domains as $domain) {
            if ($domain->getDkimEnabled()
                && !empty($domain->getDkimPrivateKey())
                && !empty($domain->getDkimSelector())) {
                $this->writePrivateKey($domain);
                $map[] = \sprintf('%s %s', $domain->getName(), $domain->getDkimSelector());
            }
        }

        $map[] = '';
        $this->writeFile(\sprintf('%s/%s', $this->path, static::MAP_FILENAME), \implode(\PHP_EOL, $map));
    }

    private function writePrivateKey(Domain $domain): void
    {
        $filename = \sprintf('%s/%s.%s.key', $this->path, $domain->getName(), $domain->getDkimSelector());

        $this->writeFile($filename, $domain->getDkimPrivateKey());
    }

    private function writeFile(string $filename, string $content): void
    {
        if (false === \file_put_contents($filename, $content)) {
            throw new \LogicException(\sprintf('Cannot write %s', $filename));
        }
    }
}

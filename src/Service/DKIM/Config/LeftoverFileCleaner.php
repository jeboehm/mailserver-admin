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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class LeftoverFileCleaner
{
    private Filesystem $filesystem;

    public function __construct(private string $path, private string $rootDir)
    {
        if (str_starts_with($this->path, './')) {
            $this->path = realpath(sprintf('%s/%s', $this->rootDir, $this->path));
        }

        $this->filesystem = new Filesystem();
    }

    public function clean(Domain ...$domains): void
    {
        $finder = new Finder();
        $finder
            ->in($this->path)
            ->name('*.key')
            ->files();

        $except = [];
        foreach ($domains as $domain) {
            if ($domain->getDkimEnabled()
                && !empty($domain->getDkimPrivateKey())
                && !empty($domain->getDkimSelector())) {
                $except[] = \sprintf('%s.%s.key', $domain->getName(), $domain->getDkimSelector());
            }
        }

        foreach ($finder as $fileInfo) {
            if (!\in_array($fileInfo->getFilename(), $except, true)) {
                $this->filesystem->remove($fileInfo->getPathname());
            }
        }
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

readonly class ApplicationVersionService
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    public function getMailserverVersion(): ?string
    {
        $versionFile = $this->projectDir . '/DMS-VERSION';

        return $this->readFile($versionFile);
    }

    public function getAdminVersion(): ?string
    {
        $versionFile = $this->projectDir . '/VERSION';

        return $this->readFile($versionFile);
    }

    /**
     * @return string|null The version number without 'v' prefix (e.g., '1.2.3') or null if file not found
     */
    private function readFile(string $versionFile): ?string
    {
        if (!file_exists($versionFile) || !is_readable($versionFile)) {
            return null;
        }

        $version = trim((string) file_get_contents($versionFile));

        if (empty($version)) {
            return null;
        }

        // Remove 'v' prefix if present (e.g., 'v1.2.3' -> '1.2.3')
        $version = ltrim($version, 'v');

        if (!is_numeric(\substr($version, 0, 1))) {
            return null;
        }

        return $version;
    }
}

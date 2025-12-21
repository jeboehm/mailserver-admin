<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class ApplicationVersionService
{
    public function __construct(
        #[Autowire('%env(file:MAILSERVER_VERSION_FILE)%')]
        private ?string $mailserverVersion = null,
        #[Autowire('%env(file:ADMIN_VERSION_FILE)%')]
        private ?string $adminVersion = null,
    ) {
    }

    public function getMailserverVersion(): ?string
    {
        return $this->mailserverVersion ? $this->formatVersion($this->mailserverVersion) : null;
    }

    public function getAdminVersion(): ?string
    {
        return $this->adminVersion ? $this->formatVersion($this->adminVersion) : null;
    }

    /**
     * @return string|null The version number without 'v' prefix (e.g., '1.2.3') or null if file not found
     */
    private function formatVersion(string $version): ?string
    {
        $version = trim($version);

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

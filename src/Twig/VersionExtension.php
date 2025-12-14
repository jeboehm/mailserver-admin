<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use App\Service\ApplicationVersionService;
use App\Service\GitHubTagService;
use Twig\Attribute\AsTwigFunction;

readonly class VersionExtension
{
    public function __construct(
        private ApplicationVersionService $applicationVersionService,
        private GitHubTagService $gitHubTagService,
    ) {
    }

    #[AsTwigFunction('admin_version')]
    public function getAdminVersion(): ?string
    {
        return $this->applicationVersionService->getAdminVersion();
    }

    #[AsTwigFunction('mailserver_version')]
    public function getMailserverVersion(): ?string
    {
        return $this->applicationVersionService->getMailserverVersion();
    }

    #[AsTwigFunction('admin_update_available')]
    public function isAdminUpdateAvailable(): bool
    {
        $currentVersion = $this->applicationVersionService->getAdminVersion();
        if (null === $currentVersion) {
            return false;
        }

        $latestVersion = $this->gitHubTagService->getLatestTag('jeboehm', 'mailserver-admin');
        if (null === $latestVersion) {
            return false;
        }

        return $currentVersion !== $latestVersion;
    }

    #[AsTwigFunction('mailserver_update_available')]
    public function isMailserverUpdateAvailable(): bool
    {
        $currentVersion = $this->applicationVersionService->getMailserverVersion();
        if (null === $currentVersion) {
            return false;
        }

        $latestVersion = $this->gitHubTagService->getLatestTag('jeboehm', 'docker-mailserver');
        if (null === $latestVersion) {
            return false;
        }

        return $currentVersion !== $latestVersion;
    }
}

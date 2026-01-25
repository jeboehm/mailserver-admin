<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class GitHubTagService
{
    private const string GITHUB_API_BASE_URL = 'https://api.github.com';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cacheApp,
    ) {
    }

    /**
     * Fetch the latest tag version from a GitHub repository.
     *
     * @param string $owner The repository owner (e.g., 'jeboehm')
     * @param string $repo  The repository name (e.g., 'mailserver-admin')
     *
     * @return string|null The latest tag version (without 'v' prefix if present) or null if not found
     */
    public function getLatestTag(string $owner, string $repo): ?string
    {
        $url = \sprintf(
            '%s/repos/%s/%s/tags',
            self::GITHUB_API_BASE_URL,
            $owner,
            $repo
        );

        $tags = $this->cacheApp->get(
            md5(__CLASS__ . $owner . $repo),
            function (ItemInterface $item) use ($url): ?array {
                $item->expiresAfter(new \DateInterval('PT8H'));
                try {
                    $response = $this->httpClient->request('GET', $url, [
                        'headers' => [
                            'Accept' => 'application/vnd.github.v3+json',
                        ],
                        'timeout' => 5, // accept higher timeout since response is cached
                    ]);

                    $statusCode = $response->getStatusCode();

                    if (200 !== $statusCode) {
                        return null;
                    }

                    return $response->toArray();
                } catch (\Exception) {
                    $item->expiresAfter(new \DateInterval('PT2H'));

                    return null;
                }
            }
        );

        if (empty($tags)) {
            return null;
        }

        // GitHub API returns tags sorted by creation date (newest first)
        // Get the first tag and extract the version
        $latestTag = $tags[0]['name'] ?? null;

        if (null === $latestTag) {
            return null;
        }

        // Remove 'v' prefix if present (e.g., 'v1.2.3' -> '1.2.3')
        return ltrim($latestTag, 'v');
    }

    /**
     * Fetch the latest tag version from a GitHub repository using full repository path.
     *
     * @param string $repositoryPath The full repository path (e.g., 'jeboehm/mailserver-admin')
     *
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     *
     * @return string|null The latest tag version (without 'v' prefix if present) or null if not found
     */
    public function getLatestTagFromPath(string $repositoryPath): ?string
    {
        $parts = explode('/', $repositoryPath, 2);
        if (2 !== \count($parts)) {
            return null;
        }

        return $this->getLatestTag($parts[0], $parts[1]);
    }
}

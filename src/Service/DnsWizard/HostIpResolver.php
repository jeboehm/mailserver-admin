<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class HostIpResolver
{
    private const string IP_SERVICE_URL = 'https://ip.uh.cx/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::string:MAILSERVER_HOST_IPS)%')]
        private ?string $overrideHostIps,
    ) {
    }

    public function resolveExpectedHostIps(): ExpectedHostIps
    {
        $override = $this->parseOverride($this->overrideHostIps);

        if (null !== $override) {
            return $override;
        }

        $response = $this->httpClient->request('GET', self::IP_SERVICE_URL, [
            'headers' => [
                'Accept' => 'text/plain',
            ],
            'timeout' => 5.0,
        ]);

        $body = $response->getContent();
        $ips = $this->extractIps($body);

        $ipv4 = [];
        $ipv6 = [];

        foreach ($ips as $ip) {
            if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $ipv4[] = $ip;
                continue;
            }

            if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                $ipv6[] = $ip;
            }
        }

        return new ExpectedHostIps(
            ipv4: array_values(array_unique($ipv4)),
            ipv6: array_values(array_unique($ipv6)),
            isOverride: false,
        );
    }

    private function parseOverride(?string $value): ?ExpectedHostIps
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $p) => '' !== $p));

        $ipv4 = [];
        $ipv6 = [];

        foreach ($parts as $ip) {
            if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $ipv4[] = $ip;
                continue;
            }

            if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                $ipv6[] = $ip;
                continue;
            }

            throw new \InvalidArgumentException(\sprintf('Invalid IP address in MAILSERVER_HOST_IPS: "%s"', $ip));
        }

        return new ExpectedHostIps(
            ipv4: array_values(array_unique($ipv4)),
            ipv6: array_values(array_unique($ipv6)),
            isOverride: true,
        );
    }

    /**
     * @return list<string>
     */
    private function extractIps(string $body): array
    {
        $tokens = preg_split('/[^0-9a-fA-F\:\.]+/', $body) ?: [];
        $ips = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ('' === $token) {
                continue;
            }

            if (false !== filter_var($token, \FILTER_VALIDATE_IP)) {
                $ips[] = $token;
            }
        }

        return array_values(array_unique($ips));
    }
}

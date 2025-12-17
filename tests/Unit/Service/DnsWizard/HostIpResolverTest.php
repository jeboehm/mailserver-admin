<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DnsWizard;

use App\Service\DnsWizard\HostIpResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HostIpResolverTest extends TestCase
{
    private MockObject|HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testOverrideDisablesHttpLookup(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $resolver = new HostIpResolver($this->httpClient, '1.2.3.4, 2001:db8::1');
        $ips = $resolver->resolveExpectedHostIps();

        self::assertTrue($ips->isOverride);
        self::assertSame(['1.2.3.4'], $ips->ipv4);
        self::assertSame(['2001:db8::1'], $ips->ipv6);
        self::assertSame(['1.2.3.4', '2001:db8::1'], $ips->all());
    }

    public function testOverrideRejectsInvalidIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->httpClient->expects($this->never())->method('request');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $resolver = new HostIpResolver($httpClient, 'not-an-ip');
        $resolver->resolveExpectedHostIps();
    }

    public function testHttpLookupParsesIpsFromBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('getContent')->willReturn("Your IPs:\n1.2.3.4\n2001:db8::1\n");

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://ip.uh.cx/', $this->callback(static fn (array $options) => isset($options['timeout'])))
            ->willReturn($response);

        $resolver = new HostIpResolver($this->httpClient, null);
        $ips = $resolver->resolveExpectedHostIps();

        self::assertFalse($ips->isOverride);
        self::assertSame(['1.2.3.4'], $ips->ipv4);
        self::assertSame(['2001:db8::1'], $ips->ipv6);
    }
}

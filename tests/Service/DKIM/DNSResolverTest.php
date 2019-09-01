<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Service\DKIM;

use App\Service\DKIM\DNSResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

class DNSResolverTest extends TestCase
{
    /** @var DNSResolver */
    private $instance;

    protected function setUp(): void
    {
        DnsMock::register(DNSResolver::class);
        $this->instance = new DNSResolver();
    }

    public function testResolve(): void
    {
        DnsMock::withMockedHosts(
            [
                '04042017._domainkey.icloud.com' => [
                    [
                        'type' => 'TXT',
                        'txt' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5SuczaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxbh/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB',
                    ],
                ],
            ]
        );

        $result = $this->instance->resolve('04042017._domainkey.icloud.com');
        $expectedResult = [
            [
                'host' => '04042017._domainkey.icloud.com',
                'class' => 'IN',
                'ttl' => 1,
                'type' => 'TXT',
                'txt' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5SuczaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxbh/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB',
            ],
        ];

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @expectedException \App\Exception\DKIM\DomainKeyNotFoundException
     * @expectedExceptionMessage Cannot get txt record for example.com
     */
    public function testResolveWithError(): void
    {
        DnsMock::withMockedHosts(
            [
                'example.com' => [],
            ]
        );

        $this->instance->resolve('example.com');
    }
}

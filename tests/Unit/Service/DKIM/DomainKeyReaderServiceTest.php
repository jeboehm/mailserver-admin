<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DKIM;

use App\Service\DKIM\DNSResolver;
use App\Service\DKIM\DomainKeyReaderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DomainKeyReaderServiceTest extends TestCase
{
    private MockObject $resolver;

    private DomainKeyReaderService $instance;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(DNSResolver::class);
        $this->instance = new DomainKeyReaderService($this->resolver);
    }

    public function testGetDomainKey(): void
    {
        $this->resolver
            ->expects($this->once())
            ->method('resolve')
            ->with('04042017._domainkey.icloud.com')
            ->willReturn(
                [
                    [
                        'txt' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5SuczaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxbh/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB',
                    ],
                ]
            );

        $this->assertEquals(
            [
                'v' => 'DKIM1',
                'k' => 'rsa',
                'p' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5SuczaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxbh/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB',
            ],
            $this->instance->getDomainKey('icloud.com', '04042017')
        );
    }

    public function testGetDomainKeyWithEntriesAnswer(): void
    {
        $this->resolver
            ->expects($this->once())
            ->method('resolve')
            ->with('04042017._domainkey.icloud.com')
            ->willReturn(
                [
                    [
                        'entries' => [
                            'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5Su',
                            'czaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxb',
                            'h/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+',
                            '/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4',
                            'bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB',
                        ],
                    ],
                ]
            );

        $this->assertEquals(
            [
                'v' => 'DKIM1',
                'k' => 'rsa',
                'p' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0L+7FmA0bMPXHC0j0aiSQ5SuczaET8W2b0/XLnw3p5oPlezyKbUih7K2fbUItZrL7NZ6+gWgksVe0vsyw0oB6tTQmvfizu1t6E/LwzCLFQH8Hkxbh/boaV3rSMJ67e45R9Yk5xijCrnaWgVS2EWL++6TStzLZb0oss1DvkWPMJFo+SBr+9Y9AGQAbJZ+8Aigjwsx//8rh+/zbYOlK+1sbH3b0myuf4CL6K0eHU0gBKSSzS8mx7hFLo9vrWuakL3BaQuaDujKAI2ia4nTyBnppYYotsVgkdG+w4bF48Hl5hNEwlDFvVC3fR8K9wrQ4w/5hYeKfuIpoPvnHFJm9/Z6/wIDAQAB',
            ],
            $this->instance->getDomainKey('icloud.com', '04042017')
        );
    }
}

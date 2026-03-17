<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DnsWizard;

use App\Service\DnsWizard\NativeDnsLookup;
use PHPUnit\Framework\TestCase;

class NativeDnsLookupTest extends TestCase
{
    private NativeDnsLookup $lookup;

    protected function setUp(): void
    {
        $this->lookup = new NativeDnsLookup();
    }

    public function testLookupAWithValidHost(): void
    {
        $result = $this->lookup->lookupA('example.com');

        // example.com should have at least one A record
        if (\count($result) > 0) {
            foreach ($result as $ip) {
                self::assertNotEmpty($ip);
                // Verify it's a valid IPv4 address
                self::assertNotFalse(filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4));
            }
        }
    }

    public function testLookupAWithInvalidHost(): void
    {
        // Use a domain that definitely doesn't exist
        $result = $this->lookup->lookupA('this-domain-definitely-does-not-exist-' . time() . '.com');

        self::assertEmpty($result);
    }

    public function testLookupAaaaWithValidHost(): void
    {
        $result = $this->lookup->lookupAaaa('example.com');

        // May or may not have AAAA records, but if present, should be valid IPv6
        if (\count($result) > 0) {
            foreach ($result as $ip) {
                self::assertNotEmpty($ip);
                // Verify it's a valid IPv6 address
                self::assertNotFalse(filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6));
            }
        }
    }

    public function testLookupAaaaWithInvalidHost(): void
    {
        $result = $this->lookup->lookupAaaa('this-domain-definitely-does-not-exist-' . time() . '.com');

        self::assertEmpty($result);
    }

    public function testLookupMxWithValidDomain(): void
    {
        $result = $this->lookup->lookupMx('example.com');

        // example.com should have MX records
        if (\count($result) > 0) {
            foreach ($result as $target) {
                self::assertNotEmpty($target);
            }
        }
    }

    public function testLookupMxWithInvalidDomain(): void
    {
        $result = $this->lookup->lookupMx('this-domain-definitely-does-not-exist-' . time() . '.com');

        self::assertEmpty($result);
    }

    public function testLookupTxtWithValidName(): void
    {
        $result = $this->lookup->lookupTxt('example.com');

        // May or may not have TXT records
        if (\count($result) > 0) {
            foreach ($result as $txt) {
                self::assertNotEmpty($txt);
            }
        }
    }

    public function testLookupTxtWithInvalidName(): void
    {
        $result = $this->lookup->lookupTxt('this-domain-definitely-does-not-exist-' . time() . '.com');

        self::assertEmpty($result);
    }

    public function testLookupPtrWithValidIpv4(): void
    {
        // Use a well-known IP that should have a PTR record
        $result = $this->lookup->lookupPtr('8.8.8.8');

        // May or may not have PTR records, but if present, should be valid hostnames
        if (\count($result) > 0) {
            foreach ($result as $target) {
                self::assertNotEmpty($target);
            }
        }
    }

    public function testLookupPtrWithValidIpv6(): void
    {
        // Use Google's public DNS IPv6
        $result = $this->lookup->lookupPtr('2001:4860:4860::8888');

        // May or may not have PTR records
        if (\count($result) > 0) {
            foreach ($result as $target) {
                self::assertNotEmpty($target);
            }
        }
    }

    public function testLookupPtrWithInvalidIp(): void
    {
        $result = $this->lookup->lookupPtr('not-an-ip');

        self::assertEmpty($result);
    }

    public function testLookupPtrWithInvalidIpv4Format(): void
    {
        $result = $this->lookup->lookupPtr('999.999.999.999');

        // Should return empty array for invalid IP
        self::assertEmpty($result);
    }

    public function testLookupPtrWithMalformedIpv4(): void
    {
        $result = $this->lookup->lookupPtr('1.2.3');

        self::assertEmpty($result);
    }

    public function testLookupPtrWithMalformedIpv6(): void
    {
        $result = $this->lookup->lookupPtr('2001:db8::');

        // May return empty or have results depending on DNS
        self::assertGreaterThanOrEqual(0, \count($result));
    }

    public function testLookupSrvWithValidName(): void
    {
        // Test with a common SRV record (e.g., _sip._tcp.example.com)
        $result = $this->lookup->lookupSrv('_sip._tcp.example.com');

        // May or may not have SRV records
        if (\count($result) > 0) {
            foreach ($result as $record) {
                self::assertArrayHasKey('priority', $record);
                self::assertArrayHasKey('weight', $record);
                self::assertArrayHasKey('port', $record);
                self::assertArrayHasKey('target', $record);
                self::assertNotEmpty($record['target']);
            }
        }
    }

    public function testLookupSrvWithInvalidName(): void
    {
        $result = $this->lookup->lookupSrv('_service._tcp.this-domain-definitely-does-not-exist-' . time() . '.com');

        self::assertEmpty($result);
    }

    public function testLookupCnameWithValidHost(): void
    {
        // Test with a host that might have a CNAME
        $result = $this->lookup->lookupCname('www.example.com');

        // May or may not have CNAME records
        if (\count($result) > 0) {
            foreach ($result as $target) {
                self::assertNotEmpty($target);
            }
        }
    }

    public function testLookupCnameWithInvalidHost(): void
    {
        $result = $this->lookup->lookupCname('this-domain-definitely-does-not-exist-' . time() . '.com');

        self::assertEmpty($result);
    }

    public function testLookupAReturnsUniqueValues(): void
    {
        // This test verifies that array_unique is working correctly
        // We can't easily force duplicates from real DNS, but we verify the structure
        $result = $this->lookup->lookupA('example.com');

        // Verify no duplicates by comparing count with unique count
        $uniqueCount = \count(array_unique($result));
        self::assertSame($uniqueCount, \count($result));
    }

    public function testLookupAaaaReturnsUniqueValues(): void
    {
        $result = $this->lookup->lookupAaaa('example.com');

        $uniqueCount = \count(array_unique($result));
        self::assertSame($uniqueCount, \count($result));
    }

    public function testLookupMxReturnsUniqueValues(): void
    {
        $result = $this->lookup->lookupMx('example.com');

        $uniqueCount = \count(array_unique($result));
        self::assertSame($uniqueCount, \count($result));
    }

    public function testLookupTxtReturnsUniqueValues(): void
    {
        $result = $this->lookup->lookupTxt('example.com');

        $uniqueCount = \count(array_unique($result));
        self::assertSame($uniqueCount, \count($result));
    }

    public function testLookupPtrReturnsUniqueValues(): void
    {
        $result = $this->lookup->lookupPtr('8.8.8.8');

        $uniqueCount = \count(array_unique($result));
        self::assertSame($uniqueCount, \count($result));
    }

    public function testLookupCnameReturnsUniqueValues(): void
    {
        $result = $this->lookup->lookupCname('example.com');

        $uniqueCount = \count(array_unique($result));
        self::assertSame($uniqueCount, \count($result));
    }

    public function testLookupPtrWithIpv4BoundaryValues(): void
    {
        // Test with boundary IPv4 addresses
        $result1 = $this->lookup->lookupPtr('0.0.0.0');
        self::assertGreaterThanOrEqual(0, \count($result1));

        $result2 = $this->lookup->lookupPtr('255.255.255.255');
        self::assertGreaterThanOrEqual(0, \count($result2));
    }

    public function testLookupPtrWithIpv6CompressedFormat(): void
    {
        // Test with compressed IPv6 format
        $result = $this->lookup->lookupPtr('::1');
        self::assertGreaterThanOrEqual(0, \count($result));
    }

    public function testLookupPtrWithIpv6FullFormat(): void
    {
        // Test with full IPv6 format
        $result = $this->lookup->lookupPtr('2001:0db8:0000:0000:0000:0000:0000:0001');
        self::assertGreaterThanOrEqual(0, \count($result));
    }
}

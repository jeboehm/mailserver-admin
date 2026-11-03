<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Fixture;

use App\Exception\DnsDeadlineExceededException;
use App\Service\DnsWizard\DnsLookupInterface;

/**
 * Replaces the resolver in the test environment, so no test depends on real DNS.
 *
 * The records are static because the test client reboots the kernel between requests.
 */
final class FakeDnsLookup implements DnsLookupInterface
{
    /** @var array<string, list<string>> */
    public static array $a = [];

    /** @var array<string, list<string>> */
    public static array $mx = [];

    /** @var array<string, list<string>> */
    public static array $txt = [];

    /** @var array<string, list<string>> */
    public static array $ptr = [];

    /**
     * Names whose lookup behaves as if the time for the tool call had just run out.
     *
     * @var list<string>
     */
    public static array $outOfTimeAt = [];

    public static function reset(): void
    {
        self::$a = [];
        self::$mx = [];
        self::$txt = [];
        self::$ptr = [];
        self::$outOfTimeAt = [];
    }

    #[\Override]
    public function lookupA(string $host): array
    {
        $this->guard($host);

        return self::$a[$host] ?? [];
    }

    #[\Override]
    public function lookupAaaa(string $host): array
    {
        $this->guard($host);

        return [];
    }

    #[\Override]
    public function lookupMx(string $domain): array
    {
        $this->guard($domain);

        return self::$mx[$domain] ?? [];
    }

    #[\Override]
    public function lookupTxt(string $name): array
    {
        $this->guard($name);

        return self::$txt[$name] ?? [];
    }

    #[\Override]
    public function lookupPtr(string $ip): array
    {
        $this->guard($ip);

        return self::$ptr[$ip] ?? [];
    }

    #[\Override]
    public function lookupSrv(string $name): array
    {
        $this->guard($name);

        return [];
    }

    #[\Override]
    public function lookupCname(string $host): array
    {
        $this->guard($host);

        return [];
    }

    private function guard(string $name): void
    {
        if (\in_array($name, self::$outOfTimeAt, true)) {
            throw new DnsDeadlineExceededException();
        }
    }
}

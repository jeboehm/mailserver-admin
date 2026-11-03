<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Gives a batch of lookups a deadline. PHP cannot time out a single dns_get_record(), and a resolver
 * that does not answer costs seconds per lookup at dozens of lookups per domain, so the deadline is
 * checked before each one. Without a limit set, lookups behave as usual.
 */
#[AsDecorator(DnsLookupInterface::class)]
final class DeadlineDnsLookup implements DnsLookupInterface
{
    private ?\DateTimeImmutable $deadline = null;

    public function __construct(
        #[AutowireDecorated]
        private readonly DnsLookupInterface $inner,
        private readonly ClockInterface $clock,
    ) {
    }

    public function limitTo(float $seconds): void
    {
        $this->deadline = $this->clock->now()->modify(\sprintf('+%d milliseconds', (int) ($seconds * 1000)));
    }

    public function removeLimit(): void
    {
        $this->deadline = null;
    }

    #[\Override]
    public function lookupA(string $host): array
    {
        $this->guard();

        return $this->inner->lookupA($host);
    }

    #[\Override]
    public function lookupAaaa(string $host): array
    {
        $this->guard();

        return $this->inner->lookupAaaa($host);
    }

    #[\Override]
    public function lookupMx(string $domain): array
    {
        $this->guard();

        return $this->inner->lookupMx($domain);
    }

    #[\Override]
    public function lookupTxt(string $name): array
    {
        $this->guard();

        return $this->inner->lookupTxt($name);
    }

    #[\Override]
    public function lookupPtr(string $ip): array
    {
        $this->guard();

        return $this->inner->lookupPtr($ip);
    }

    #[\Override]
    public function lookupSrv(string $name): array
    {
        $this->guard();

        return $this->inner->lookupSrv($name);
    }

    #[\Override]
    public function lookupCname(string $host): array
    {
        $this->guard();

        return $this->inner->lookupCname($host);
    }

    /**
     * @throws DnsDeadlineExceededException
     */
    private function guard(): void
    {
        if (null !== $this->deadline && $this->clock->now() >= $this->deadline) {
            throw new DnsDeadlineExceededException('The time for these DNS lookups has run out.');
        }
    }
}

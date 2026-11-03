<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DnsWizard;

use App\Entity\Domain;
use App\Exception\DnsDeadlineExceededException;
use App\Service\DnsWizard\Check\DnsCheckInterface;
use App\Service\DnsWizard\DeadlineDnsLookup;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\DnsWizardValidator;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\TimeBoxedDnsValidation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Every lookup takes five seconds of mock time, as they did on a stack with an unreachable resolver.
 */
class TimeBoxedDnsValidationTest extends TestCase
{
    private const float LOOKUP_SECONDS = 5.0;

    private MockClock $clock;
    private DeadlineDnsLookup $lookup;
    private TimeBoxedDnsValidation $validation;
    private ExpectedHostIps $ips;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock();

        $slow = $this->createStub(DnsLookupInterface::class);
        $slow->method('lookupA')->willReturnCallback(function (string $host): array {
            $this->clock->sleep(self::LOOKUP_SECONDS);

            return ['192.0.2.1'];
        });

        $this->lookup = new DeadlineDnsLookup($slow, $this->clock);
        $this->validation = new TimeBoxedDnsValidation(new DnsWizardValidator([$this->lookingUpCheck()]), $this->lookup);
        $this->ips = new ExpectedHostIps(['192.0.2.1'], [], true);
    }

    public function testChecksEverythingWithinTheBudget(): void
    {
        $run = $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test', 'b.test'), true, 60.0);

        self::assertNotNull($run->mailHost);
        self::assertCount(1, $run->mailHost);
        self::assertSame(['a.test', 'b.test'], array_keys($run->domains));
        self::assertSame(2, $run->consumed);
        self::assertSame([], $run->timedOut);
    }

    public function testStopsWhenTheBudgetRunsOutAndLeavesTheRestForTheNextCall(): void
    {
        // The mail host and each domain take two lookups, so a.test ends at 20s and b.test is refused.
        $run = $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test', 'b.test', 'c.test'), true, 17.0);

        self::assertSame(['a.test'], array_keys($run->domains));
        self::assertSame(1, $run->consumed);
        self::assertSame([], $run->timedOut);
    }

    public function testStopsSoonAfterTheBudgetRunsOut(): void
    {
        $startedAt = $this->clock->now();

        $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test', 'b.test', 'c.test'), true, 17.0);

        // The lookup in flight when the deadline passes cannot be interrupted.
        self::assertLessThanOrEqual(17.0 + self::LOOKUP_SECONDS, (float) $this->clock->now()->format('U.u') - (float) $startedAt->format('U.u'));
    }

    public function testMailHostUsingUpTheBudgetLeavesAllDomainsForTheNextCall(): void
    {
        $run = $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test'), true, 7.0);

        self::assertNotNull($run->mailHost);
        self::assertSame([], $run->domains);
        self::assertSame(0, $run->consumed);
        self::assertSame([], $run->timedOut);
    }

    public function testMailHostNotFinishingIsReportedAsNotChecked(): void
    {
        $run = $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test'), true, 2.0);

        self::assertNull($run->mailHost);
        self::assertTrue($run->mailHostTimedOut);
        self::assertSame(0, $run->consumed);
    }

    public function testDomainThatCannotFinishInAWholeBudgetIsPassedOverSoTheCallerGetsPastIt(): void
    {
        // The second lookup of a domain starts at 5s. With a budget of 3s it can never finish.
        $run = $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test', 'b.test'), false, 3.0);

        self::assertNull($run->mailHost);
        self::assertFalse($run->mailHostTimedOut);
        self::assertSame([], $run->domains);
        self::assertSame(['a.test'], $run->timedOut);
        self::assertSame(1, $run->consumed);
    }

    public function testLookupsOutsideARunHaveNoDeadline(): void
    {
        $this->validation->run('mail.example.com', $this->ips, $this->domains('a.test', 'b.test'), false, 1.0);
        $this->clock->sleep(3600);

        self::assertSame(['192.0.2.1'], $this->lookup->lookupA('mail.example.com'));
    }

    public function testADeadlineIsNotAnEmptyResult(): void
    {
        $this->lookup->limitTo(1.0);
        $this->clock->sleep(2.0);

        $this->expectException(DnsDeadlineExceededException::class);

        $this->lookup->lookupA('mail.example.com');
    }

    /**
     * @return list<Domain>
     */
    private function domains(string ...$names): array
    {
        return array_map(static function (string $name): Domain {
            $domain = new Domain();
            $domain->setName($name);

            return $domain;
        }, array_values($names));
    }

    private function lookingUpCheck(): DnsCheckInterface
    {
        $row = static fn (string $subject): DnsWizardRow => new DnsWizardRow('scope', $subject, 'A', [], [], DnsWizardStatus::OK, 'ok');
        $check = $this->createStub(DnsCheckInterface::class);

        $check->method('validateMailHost')->willReturnCallback(function (string $mailname) use ($row): array {
            $this->lookup->lookupA($mailname);
            $this->lookup->lookupA($mailname);

            return [$row($mailname)];
        });
        $check->method('validateDomain')->willReturnCallback(function (string $mailname, array $expected, Domain $domain) use ($row): array {
            $this->lookup->lookupA($domain->getName());
            $this->lookup->lookupA($domain->getName());

            return [$row($domain->getName())];
        });

        return $check;
    }
}

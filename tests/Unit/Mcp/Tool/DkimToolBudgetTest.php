<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Mcp\Tool;

use App\Entity\Domain;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\Tool\DkimTool;
use App\Mcp\ToolEntityWriter;
use App\Repository\DomainRepository;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\DomainKeyReaderService;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use App\Service\DKIM\KeyRecreationService;
use App\Service\DKIM\RecordParser;
use App\Service\DnsWizard\Check\DnsCheckInterface;
use App\Service\DnsWizard\DeadlineDnsLookup;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardValidator;
use App\Service\DnsWizard\TimeBoxedDnsValidation;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DkimPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A lookup that takes longer than any budget, followed by another one. Only a lookup running under the
 * budget refuses the second, so that is what shows the tool used it.
 */
class DkimToolBudgetTest extends TestCase
{
    public function testStatusRunsUnderTheDnsBudget(): void
    {
        $clock = new MockClock();
        $deadline = null;

        $slow = $this->createStub(DnsLookupInterface::class);
        $slow->method('lookupTxt')->willReturnCallback(static function (string $name) use ($clock, &$deadline): array {
            if ('again' === $name) {
                return [];
            }

            $clock->sleep(3600);

            return $deadline->lookupTxt('again');
        });

        $deadline = new DeadlineDnsLookup($slow, $clock);

        $keys = new KeyGenerationService();
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimSelector('dkim');
        $domain->setDkimPrivateKey($keys->createKeyPair()->getPrivate());

        $domains = $this->createStub(DomainRepository::class);
        $domains->method('findOneBy')->willReturn($domain);

        $tool = new DkimTool(
            $domains,
            new DKIMStatusService(new DomainKeyReaderService($deadline, new RecordParser()), new FormatterService(), $keys, new RecordParser()),
            new KeyRecreationService($keys, $this->createStub(EntityManagerInterface::class), new DkimPolicy()),
            new TimeBoxedDnsValidation(new DnsWizardValidator([$this->createStub(DnsCheckInterface::class)]), $deadline),
            new CurrentActor($this->createStub(Security::class)),
            new ToolEntityWriter($this->createStub(EntityManagerInterface::class), $this->createStub(ValidatorInterface::class)),
            new ToolAuditLog(new Logger('test'), new RequestStack()),
        );

        $status = $tool->status('example.com');

        self::assertTrue($status['timed_out']);
        self::assertNull($status['record_found']);
        self::assertNull($status['record_valid']);
        self::assertStringContainsString('p=', $status['expected_record']);

        self::assertSame([], $deadline->lookupTxt('again'), 'The budget must end with the tool call.');
    }
}

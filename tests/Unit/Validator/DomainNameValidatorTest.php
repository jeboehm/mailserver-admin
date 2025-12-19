<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Validator;

use App\Validator\DomainName;
use App\Validator\DomainNameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class DomainNameValidatorTest extends TestCase
{
    private DomainNameValidator $validator;

    private DomainName $constraint;

    private MockObject&ExecutionContextInterface $context;

    protected function setUp(): void
    {
        $this->validator = new DomainNameValidator();
        $this->constraint = new DomainName();
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    #[DataProvider('domainNameProvider')]
    public function testValidateDomainName(mixed $value, bool $expectedValid): void
    {
        if ($expectedValid) {
            $this->context
                ->expects($this->never())
                ->method('buildViolation');
        } else {
            $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
            $this->context
                ->expects($this->once())
                ->method('buildViolation')
                ->with($this->constraint->message)
                ->willReturn($violationBuilder);

            $violationBuilder
                ->expects($this->once())
                ->method('setParameter')
                ->with($this->anything(), $this->anything())
                ->willReturnSelf();

            $violationBuilder
                ->expects($this->once())
                ->method('addViolation');
        }

        $this->validator->validate($value, $this->constraint);
    }

    public function testValidateNull(): void
    {
        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate(null, $this->constraint);
    }

    public function testValidateEmptyString(): void
    {
        $this->context->expects($this->never())->method('buildViolation');
        $this->validator->validate('', $this->constraint);
    }

    public function testValidateNonString(): void
    {
        $this->context->expects($this->never())->method('buildViolation');
        $this->expectException(UnexpectedTypeException::class);
        $this->expectExceptionMessage('Expected argument of type "string", "int" given');

        $this->validator->validate(123, $this->constraint);
    }

    /**
     * @return array<int, array{0: mixed, 1: bool}>
     */
    public static function domainNameProvider(): array
    {
        return [
            // Valid domain names
            ['boehm.de', true],
            ['xn--bhm-sna.de', true],
            ['boe-hm.co.uk', true],
            ['mail.boehm.co.uk', true],
            ['example.com', true],
            ['subdomain.example.com', true],
            ['a.co.uk', true],
            ['test-domain.com', true],
            ['123example.com', true],
            ['example123.com', true],
            ['xn--example.com', true],
            ['very-long-subdomain-name.example.com', true],

            // Invalid domain names
            ['invalid', false], // No TLD
            ['.example.com', false], // Leading dot
            ['example.com.', false], // Trailing dot
            ['example..com', false], // Double dot
            ['-example.com', false], // Leading hyphen in label
            ['example-.com', false], // Trailing hyphen in label
            ['example.c', false], // TLD too short
            ['example', false], // No TLD
            ['example .com', false], // Space in domain (fails ASCII check)
            ['example\t.com', false], // Tab in domain (fails ASCII check)
            ['example.com/path', false], // Path component
            ['user@example.com', false], // Email address
            ['http://example.com', false], // URL
            ['example.com:80', false], // Port number
            [str_repeat('a', 64) . '.com', false], // Label too long (64 chars)
            [str_repeat('a', 254) . '.com', false], // Total length too long (254+ chars)
            ['münchen.de', false], // UTF-8 characters (not punycode)
            ['café.fr', false], // UTF-8 characters
            ['тест.рф', false], // Cyrillic characters
            ['例子.中国', false], // Chinese characters
            ['xn--invalid-', false], // Incomplete punycode
            ['xn--', false], // Empty punycode
        ];
    }
}

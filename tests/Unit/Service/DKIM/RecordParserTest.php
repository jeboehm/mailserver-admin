<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DKIM;

use App\Service\DKIM\RecordParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecordParserTest extends TestCase
{
    private RecordParser $instance;

    protected function setUp(): void
    {
        $this->instance = new RecordParser();
    }

    /**
     * @param array<string, string> $expect
     */
    #[DataProvider('dataProviderForTestParse')]
    public function testParse(array $expect, string $record): void
    {
        self::assertSame($expect, $this->instance->parse($record));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function dataProviderForTestParse(): array
    {
        $tags = ['v' => 'DKIM1', 'h' => 'sha256', 't' => 's', 'p' => 'abc123'];

        return [
            'canonical form' => [
                $tags,
                'v=DKIM1; h=sha256; t=s; p=abc123',
            ],
            'no spaces after the separators' => [
                $tags,
                'v=DKIM1;h=sha256;t=s;p=abc123',
            ],
            'generous whitespace' => [
                $tags,
                "v = DKIM1 ;\th=sha256 ; t=s ;  p=abc123 ",
            ],
            'trailing semicolon' => [
                $tags,
                'v=DKIM1; h=sha256; t=s; p=abc123;',
            ],
            'trailing semicolon and whitespace' => [
                $tags,
                'v=DKIM1; h=sha256; t=s; p=abc123 ; ',
            ],
            'empty segments' => [
                $tags,
                'v=DKIM1;; h=sha256; ; t=s; p=abc123',
            ],
            'reordered tags' => [
                ['p' => 'abc123', 'v' => 'DKIM1', 'h' => 'sha256', 't' => 's'],
                'p=abc123; v=DKIM1; h=sha256; t=s',
            ],
            'additional tags are kept' => [
                ['v' => 'DKIM1', 'k' => 'rsa', 'h' => 'sha256', 't' => 's', 'p' => 'abc123'],
                'v=DKIM1; k=rsa; h=sha256; t=s; p=abc123',
            ],
            'a malformed segment does not discard the record' => [
                ['v' => 'DKIM1', 'p' => 'abc123'],
                'v=DKIM1; garbage; p=abc123',
            ],
            'a segment without a tag name is skipped' => [
                ['v' => 'DKIM1', 'p' => 'abc123'],
                'v=DKIM1; =nameless; p=abc123',
            ],
            'whitespace inside the key is removed' => [
                ['v' => 'DKIM1', 'p' => 'abc123def456'],
                "v=DKIM1; p=abc123\n  def456",
            ],
            'values containing an equals sign are kept intact' => [
                ['v' => 'DKIM1', 'p' => 'abc123=='],
                'v=DKIM1; p=abc123==',
            ],
            'the escaping of a bind zone file ends up in the values' => [
                ['v' => 'DKIM1\\', 'h' => 'sha256\\', 't' => 's\\', 'p' => 'abc123'],
                'v=DKIM1\; h=sha256\; t=s\; p=abc123',
            ],
            'empty record' => [
                [],
                '',
            ],
            'record without any tag' => [
                [],
                'this is not a dkim record',
            ],
        ];
    }
}

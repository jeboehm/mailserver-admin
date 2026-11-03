<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Service\Mailbox\BodyDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BodyDecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function completeBodies(): iterable
    {
        yield 'utf-8 in 8bit' => ["Gr\xC3\xBC\xC3\x9Fe", '8bit', 'utf-8', 'Grüße'];
        yield 'latin-1 in 8bit' => ["Gr\xFC\xDFe", '8bit', 'ISO-8859-1', 'Grüße'];
        yield 'windows-1252' => ["\x80 5", '8bit', 'windows-1252', '€ 5'];
        yield 'quoted-printable utf-8' => ['Gr=C3=BC=C3=9Fe', 'quoted-printable', 'UTF-8', 'Grüße'];
        yield 'quoted-printable latin-1' => ['Gr=FC=DFe', 'QUOTED-PRINTABLE', 'iso-8859-1', 'Grüße'];
        yield 'base64 japanese' => [base64_encode('こんにちは'), 'base64', 'utf-8', 'こんにちは'];
        yield 'base64 with line breaks' => ["44GT44KT\r\n44Gr44Gh\r\n44Gv", 'base64', 'utf-8', 'こんにちは'];
        yield 'iso-2022-jp' => ["\e\$B\$3\$s\$K\$A\$O\e(B", '7bit', 'ISO-2022-JP', 'こんにちは'];
        yield 'no charset means utf-8' => ['plain', '7bit', null, 'plain'];
        yield 'no encoding' => ['plain', null, 'us-ascii', 'plain'];
    }

    #[DataProvider('completeBodies')]
    public function testDecodesTransferEncodingAndCharset(string $raw, ?string $encoding, ?string $charset, string $expected): void
    {
        self::assertSame($expected, new BodyDecoder()->decode($raw, $encoding, $charset));
    }

    public function testUnknownCharsetStillGivesValidUtf8(): void
    {
        $text = new BodyDecoder()->decode("ok \xFF", '8bit', 'x-made-up');

        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        self::assertStringStartsWith('ok', $text);
    }

    public function testRandomBytesDeclaredAsUtf8GiveValidUtf8(): void
    {
        $text = new BodyDecoder()->decode(random_bytes(4096), '8bit', 'utf-8');

        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function testPartialFetchCutInsideAQuotedPrintableEscape(): void
    {
        self::assertSame('Gr', new BodyDecoder()->decode('Gr=C', 'quoted-printable', 'utf-8'));
        self::assertSame('Gr', new BodyDecoder()->decode('Gr=', 'quoted-printable', 'utf-8'));
    }

    public function testPartialFetchCutInsideABase64Group(): void
    {
        self::assertSame('こん', new BodyDecoder()->decode(substr(base64_encode('こんにちは'), 0, 10), 'base64', 'utf-8'));
    }

    public function testPartialFetchCutInsideAUtf8Character(): void
    {
        self::assertSame('Gr', new BodyDecoder()->decode("Gr\xC3", '8bit', 'utf-8'));
    }

    public function testHeaderValuesAreDecodedAndUnfolded(): void
    {
        $decoder = new BodyDecoder();

        self::assertSame('Grüße long subject', $decoder->header("=?UTF-8?B?R3LDvMOfZQ==?= long\r\n subject"));
        self::assertSame('Jörg <j@example.test>', $decoder->header('=?iso-8859-1?Q?J=F6rg?= <j@example.test>'));
    }

    public function testRawEightBitHeadersAreReadAsLatin1(): void
    {
        self::assertSame('Grüße', new BodyDecoder()->header("Gr\xFC\xDFe"));
    }
}

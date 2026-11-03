<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Service\Mailbox\HtmlToText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlToTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'paragraphs' => ['<p>One</p><p>Two</p>', "One\n\nTwo"];
        yield 'line break' => ['One<br>Two', "One\nTwo"];
        yield 'collapses source whitespace' => ["<p>One\n   two</p>", 'One two'];
        yield 'entities' => ['<p>Gr&uuml;&szlig;e &amp; &lt;mehr&gt;</p>', 'Grüße & <mehr>'];
        yield 'utf-8 text' => ['<p>Grüße こんにちは</p>', 'Grüße こんにちは'];
        yield 'drops scripts and styles' => ['<style>p{}</style><script>evil()</script><p>Kept</p>', 'Kept'];
        yield 'drops the head' => ['<html><head><title>T</title></head><body>Body</body></html>', 'Body'];
        yield 'link target' => ['<a href="https://example.test/x">Click</a>', 'Click (https://example.test/x)'];
        yield 'link whose text is the target' => ['<a href="https://example.test/">https://example.test/</a>', 'https://example.test/'];
        yield 'image alternative' => ['<img src="x.png" alt="Logo"> text', 'Logo text'];
        yield 'list items' => ['<ul><li>One</li><li>Two</li></ul>', "- One\n- Two"];
        yield 'table cells' => ['<table><tr><td>A</td><td>B</td></tr><tr><td>C</td></tr></table>', "A B\nC"];
        yield 'cut off by a partial fetch' => ['<p>One</p><p>Two <b', "One\n\nTwo"];
        yield 'not html at all' => ['just text', 'just text'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('documents')]
    public function testConvertsHtmlToText(string $html, string $expected): void
    {
        self::assertSame($expected, new HtmlToText()->convert($html));
    }
}

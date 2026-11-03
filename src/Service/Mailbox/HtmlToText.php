<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Reads the visible text out of an HTML mail. Input must be UTF-8 and may be cut off anywhere.
 */
final readonly class HtmlToText
{
    private const array IGNORED = ['HEAD', 'SCRIPT', 'STYLE', 'TEMPLATE'];
    private const array PARAGRAPHS = ['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BLOCKQUOTE', 'PRE', 'UL', 'OL', 'TABLE', 'HR', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER'];
    private const array LINES = ['BR', 'TR', 'LI'];

    public function convert(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $document = HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8');
        $text = $this->textOf($document->documentElement ?? $document);

        $text = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function textOf(Node $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $text .= (string) preg_replace('/\s+/', ' ', (string) $child->textContent);
            } elseif ($child instanceof Element) {
                $text .= $this->elementText($child);
            }
        }

        return $text;
    }

    private function elementText(Element $element): string
    {
        $name = strtoupper($element->tagName);

        if (\in_array($name, self::IGNORED, true)) {
            return '';
        }

        $text = trim($this->textOf($element));

        return match (true) {
            'IMG' === $name => (string) $element->getAttribute('alt'),
            'BR' === $name => "\n",
            'A' === $name => $this->link($element, $text),
            'LI' === $name => '- ' . $text . "\n",
            'TD' === $name, 'TH' === $name => $text . ' ',
            \in_array($name, self::LINES, true) => $text . "\n",
            \in_array($name, self::PARAGRAPHS, true) => "\n\n" . $text . "\n\n",
            default => $this->textOf($element),
        };
    }

    private function link(Element $element, string $text): string
    {
        $target = (string) $element->getAttribute('href');

        return '' === $target || $target === $text ? $text : \sprintf('%s (%s)', $text, $target);
    }
}

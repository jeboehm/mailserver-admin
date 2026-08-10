<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

use App\Exception\DKIM\DomainKeyNotFoundException;
use App\Service\DnsWizard\DnsLookupInterface;

readonly class DomainKeyReaderService
{
    public function __construct(private DnsLookupInterface $resolver, private RecordParser $parser)
    {
    }

    /**
     * Reads the DKIM key record published at <selector>._domainkey.<domain>.
     *
     * @throws DomainKeyNotFoundException if no DKIM record is published at that name
     */
    public function getDomainKey(string $domain, string $selector): DomainKey
    {
        $dkimDomain = \sprintf('%s._domainkey.%s', $selector, $domain);
        $values = $this->resolver->lookupTxt($dkimDomain);

        foreach ($values as $index => $value) {
            if (!$this->isDomainKey($this->parser->parse($value))) {
                continue;
            }

            $raw = $value . implode('', $this->takeContinuations($values, $index + 1));

            return new DomainKey($raw, $this->parser->parse($raw));
        }

        throw new DomainKeyNotFoundException(\sprintf('No DKIM record found at "%s".', $dkimDomain));
    }

    /**
     * Collects the values following a DKIM record that are continuations of it.
     *
     * Resolvers may hand back the character strings of a single TXT record separately. A
     * continuation carries no tags of its own, which is what distinguishes it from an unrelated
     * TXT record published at the same name.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function takeContinuations(array $values, int $offset): array
    {
        $continuations = [];

        foreach (\array_slice($values, $offset) as $value) {
            if ([] !== $this->parser->parse($value)) {
                break;
            }

            $continuations[] = $value;
        }

        return $continuations;
    }

    /**
     * @param array<string, string> $tags
     */
    private function isDomainKey(array $tags): bool
    {
        return \array_key_exists('p', $tags) || 'DKIM1' === ($tags['v'] ?? null);
    }
}

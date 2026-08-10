<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

use App\Entity\Domain;
use App\Exception\DKIM\DomainKeyNotFoundException;

class DKIMStatusService
{
    public function __construct(private readonly DomainKeyReaderService $domainKeyReaderService, private readonly FormatterService $formatterService, private readonly KeyGenerationService $keyGenerationService, private readonly RecordParser $recordParser)
    {
    }

    /**
     * Compares the published DKIM record with the one expected for this domain.
     *
     * The comparison is deliberately not a string comparison: DNS providers differ in tag order,
     * spacing, added tags and how they chunk long values, all of which are valid. What has to
     * match is the public key, so the base64 of the published "p" tag is decoded and compared
     * against the key derived from the stored private key.
     */
    public function getStatus(Domain $domain): DKIMStatus
    {
        if (empty($domain->getDkimPrivateKey()) || empty($domain->getDkimSelector())) {
            return new DKIMStatus($domain->getDkimEnabled(), false, false, '');
        }

        $expectedRecord = $this->formatterService->getTXTRecord(
            $this->keyGenerationService->extractPublicKey($domain->getDkimPrivateKey()),
            KeyGenerationService::DIGEST_ALGORITHM
        );

        try {
            $published = $this->domainKeyReaderService->getDomainKey($domain->getName(), $domain->getDkimSelector());
        } catch (DomainKeyNotFoundException) {
            return new DKIMStatus($domain->getDkimEnabled(), false, false, '', $expectedRecord);
        }

        $expectedKey = $this->recordParser->parse($expectedRecord)['p'] ?? '';
        $issues = $this->collectIssues($published, $expectedKey);

        return new DKIMStatus(
            $domain->getDkimEnabled(),
            true,
            [] === $issues,
            $published->raw,
            $expectedRecord,
            $issues
        );
    }

    /**
     * @return list<string>
     */
    private function collectIssues(DomainKey $published, string $expectedKey): array
    {
        $issues = [];

        if (str_contains($published->raw, '\\')) {
            $issues[] = 'The published record contains backslashes. Your DNS provider stored the escaping literally — publish the value with plain semicolons instead.';
        }

        $version = $published->getTag('v');

        if (null !== $version && 'DKIM1' !== $version) {
            $issues[] = \sprintf('The version tag is "v=%s" instead of "v=DKIM1".', $version);
        }

        $issue = $this->checkPublicKey($published->getTag('p'), $expectedKey);

        if (null !== $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    private function checkPublicKey(?string $publishedKey, string $expectedKey): ?string
    {
        if (null === $publishedKey) {
            return 'The record does not contain a public key ("p=" tag).';
        }

        if ('' === $publishedKey) {
            return 'The public key is empty, which revokes the key for this selector.';
        }

        $decodedPublished = base64_decode($publishedKey, true);

        if (false === $decodedPublished) {
            return 'The public key is not valid base64. It was probably truncated or altered while it was published.';
        }

        $decodedExpected = base64_decode($expectedKey, true);

        if (false === $decodedExpected || '' === $decodedExpected) {
            return 'The public key of this domain could not be determined.';
        }

        if (!hash_equals($decodedExpected, $decodedPublished)) {
            return 'The published public key belongs to a different key pair. Copy the expected value again.';
        }

        return null;
    }
}

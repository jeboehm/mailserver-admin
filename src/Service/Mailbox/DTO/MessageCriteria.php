<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox\DTO;

final readonly class MessageCriteria
{
    public function __construct(
        public ?string $text = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $subject = null,
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $before = null,
        public bool $unseenOnly = false,
    ) {
    }

    /**
     * Arguments for UID SEARCH. Values go as literals: counted bytes need no quoting, so nothing a
     * caller passes can become a search key, and they may contain non-ASCII characters.
     *
     * @return list<string|array{string, string}>
     */
    public function toSearchArguments(): array
    {
        $arguments = ['CHARSET', 'UTF-8'];

        foreach (['TEXT' => $this->text, 'FROM' => $this->from, 'TO' => $this->to, 'SUBJECT' => $this->subject] as $key => $value) {
            if (null !== $value) {
                $arguments[] = $key;
                $arguments[] = [\sprintf('{%d}', \strlen($value)), $value];
            }
        }

        foreach (['SINCE' => $this->since, 'BEFORE' => $this->before] as $key => $date) {
            if (null !== $date) {
                $arguments[] = $key;
                $arguments[] = $date->format('j-M-Y');
            }
        }

        if ($this->unseenOnly) {
            $arguments[] = 'UNSEEN';
        }

        return 2 === \count($arguments) ? [...$arguments, 'ALL'] : $arguments;
    }
}

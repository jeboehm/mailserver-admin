<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Factory;

use App\Entity\User;
use App\Exception\DomainNotFoundException;
use App\Repository\DomainRepository;

readonly class UserFactory
{
    public function __construct(
        private DomainRepository $domainRepository,
    ) {
    }

    public function createFromEmailAddress(string $emailAddress): User
    {
        $this->validateEmailAddress($emailAddress);

        [$name, $domainName] = explode('@', \mb_strtolower($emailAddress), 2);

        $domain = $this->domainRepository->findOneBy(['name' => $domainName]);

        if (null === $domain) {
            throw DomainNotFoundException::fromDomainName($domainName);
        }

        $user = new User();
        $user->setName($name);
        $user->setDomain($domain);

        return $user;
    }

    private function validateEmailAddress(string $emailAddress): void
    {
        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid email address.', $emailAddress));
        }
    }
}

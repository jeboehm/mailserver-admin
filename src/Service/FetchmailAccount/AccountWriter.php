<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\FetchmailAccount;

use App\Entity\FetchmailAccount;
use App\Repository\FetchmailAccountRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Predis\Client;
use Symfony\Component\Serializer\SerializerInterface;

#[AsEntityListener(event: Events::postFlush, method: 'write', entity: FetchmailAccount::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'write', entity: FetchmailAccount::class)]
class AccountWriter
{
    private const string KEY_ACCOUNTS = 'fetchmail_accounts';

    public function __construct(
        private readonly Client $redis,
        private readonly FetchmailAccountRepository $repository,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function write(): void
    {
        /** @var FetchmailAccount[] $fetchmailAccounts */
        $fetchmailAccounts = $this->repository->findAll();
        $data = [];

        foreach ($fetchmailAccounts as $fetchmailAccount) {
            $data[] = AccountData::fromFetchmailAccount($fetchmailAccount);
        }

        $this->redis->set(self::KEY_ACCOUNTS, $this->serializer->serialize($data, 'json'));
    }
}

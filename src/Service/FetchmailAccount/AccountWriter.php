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
use Predis\ClientInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[AsEntityListener(event: Events::postUpdate, method: 'write', entity: FetchmailAccount::class)]
#[AsEntityListener(event: Events::postPersist, method: 'write', entity: FetchmailAccount::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: FetchmailAccount::class)]
readonly class AccountWriter
{
    public function __construct(
        private ClientInterface $redis,
        private FetchmailAccountRepository $repository,
        private SerializerInterface $serializer,
    ) {
    }

    public function postRemove(FetchmailAccount $account): void
    {
        $this->redis->del(RedisKeys::createRuntimeKey((int) $account->getId()));
        $this->write();
    }

    public function write(): void
    {
        /** @var FetchmailAccount[] $fetchmailAccounts */
        $fetchmailAccounts = $this->repository->findAll();
        $data = [];

        foreach ($fetchmailAccounts as $fetchmailAccount) {
            $data[] = AccountData::fromFetchmailAccount($fetchmailAccount);
        }

        $this->redis->set(RedisKeys::createAccountsKey(), $this->serializer->serialize($data, 'json'));
    }
}

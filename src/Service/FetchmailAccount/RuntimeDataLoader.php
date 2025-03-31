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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Predis\Client;
use Symfony\Component\Serializer\SerializerInterface;

#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: FetchmailAccount::class)]
readonly class RuntimeDataLoader
{
    public function __construct(
        private Client $redis,
        private SerializerInterface $serializer,
    ) {
    }

    public function postLoad(FetchmailAccount $fetchmailAccount): void
    {
        $data = $this->redis->get(RedisKeys::createRuntimeKey($fetchmailAccount->getId()));

        if (null === $data) {
            return;
        }

        $runtimeData = $this->serializer->deserialize($data, RuntimeData::class, 'json');

        if (!($runtimeData instanceof RuntimeData)) {
            return;
        }

        $fetchmailAccount->lastRun = $runtimeData->lastRun;
        $fetchmailAccount->isSuccess = $runtimeData->isSuccess;
        $fetchmailAccount->lastLog = $runtimeData->lastLog;
    }
}

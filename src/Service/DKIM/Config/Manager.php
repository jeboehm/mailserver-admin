<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM\Config;

use App\Entity\Domain;
use Doctrine\ORM\EntityManagerInterface;

class Manager
{
    public function __construct(private LeftoverFileCleaner $cleaner, private MapGenerator $generator, private EntityManagerInterface $manager)
    {
    }

    public function refresh(): void
    {
        /** @var Domain $domains */
        $domains = $this->manager->getRepository(Domain::class)->findAll();

        $this->cleaner->clean(...$domains);
        $this->generator->generate(...$domains);
    }
}

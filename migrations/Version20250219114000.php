<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250219114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set static dkim selector (breaking change, adapt your DNS settings!)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->platform instanceof AbstractMySQLPlatform) {
            return;
        }

        // Only installations that came through the 2018 rename have this table;
        // fresh ones get their schema from the baseline migration instead.
        if (!$schema->hasTable('mail_domains')) {
            return;
        }

        // Single quotes: double quotes denote an identifier under ANSI_QUOTES.
        $this->addSql(
            "UPDATE mail_domains SET dkim_selector = 'dkim'"
        );
    }

    public function down(Schema $schema): void
    {
    }
}

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

class Version20180520173959 extends AbstractMigration
{
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

        $this->addSql(
            'ALTER TABLE mail_users ADD enabled TINYINT(1) NOT NULL, ADD send_only TINYINT(1) NOT NULL, ADD quota INT NOT NULL'
        );
        $this->addSql('UPDATE mail_users SET enabled = 1');
    }
}

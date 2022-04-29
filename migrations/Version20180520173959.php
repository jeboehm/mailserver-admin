<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20180520173959 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE mail_users ADD enabled TINYINT(1) NOT NULL, ADD send_only TINYINT(1) NOT NULL, ADD quota INT NOT NULL'
        );
        $this->addSql('UPDATE mail_users SET enabled = 1');
    }

    public function down(Schema $schema): void
    {
    }
}

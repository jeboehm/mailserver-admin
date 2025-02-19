<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20180320171339 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_aliases RENAME INDEX idx_5f12bb39115f0ee5 TO IDX_85AF3A56115F0EE5');
        $this->addSql('ALTER TABLE mail_domains CHANGE name name VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_56C63EF25E237E06 ON mail_domains (name)');
        $this->addSql('DROP INDEX UNIQ_1483A5E95E237E06 ON mail_users');
        $this->addSql('ALTER TABLE mail_users RENAME INDEX idx_1483a5e9115f0ee5 TO IDX_20400786115F0EE5');
    }
}

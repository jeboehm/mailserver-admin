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

final class Version20250319161225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'add fetchmail_accounts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fetchmail_accounts (id INT AUTO_INCREMENT NOT NULL, config_host VARCHAR(255) NOT NULL COLLATE `utf8_unicode_ci`, config_protocol VARCHAR(50) NOT NULL COLLATE `utf8_unicode_ci`, config_port SMALLINT NOT NULL, config_username VARCHAR(255) NOT NULL COLLATE `utf8_unicode_ci`, config_password VARCHAR(255) NOT NULL COLLATE `utf8_unicode_ci`, config_ssl TINYINT(1) NOT NULL, config_verify_ssl TINYINT(1) NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_F61C5867A76ED395 (user_id), UNIQUE INDEX host_username_idx (config_host, config_username), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE fetchmail_accounts ADD CONSTRAINT FK_F61C5867A76ED395 FOREIGN KEY (user_id) REFERENCES mail_users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fetchmail_accounts DROP FOREIGN KEY FK_F61C5867A76ED395');
        $this->addSql('DROP TABLE fetchmail_accounts');
    }
}

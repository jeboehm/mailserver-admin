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

final class Version20190610121554 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'mysql'."
        );

        $this->addSql(
            'ALTER TABLE mail_domains ADD dkim_enabled TINYINT(1) NOT NULL, ADD dkim_selector VARCHAR(255) NOT NULL, ADD dkim_private_key LONGTEXT NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'mysql'."
        );

        $this->addSql(
            'ALTER TABLE mail_domains DROP dkim_enabled, DROP dkim_selector, DROP dkim_private_key'
        );
    }
}

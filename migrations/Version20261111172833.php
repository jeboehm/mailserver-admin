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
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Existing tokens get the primary mode, which is what they could do before.
 */
final class Version20261111172833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the sender mode and the sender address to API tokens';
    }

    public function up(Schema $schema): void
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE api_tokens ADD sender_mode VARCHAR(16) DEFAULT \'primary\' NOT NULL, ADD sender_address VARCHAR(255) DEFAULT NULL');

            return;
        }

        if (!$this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE api_tokens ADD sender_mode VARCHAR(16) DEFAULT \'primary\' NOT NULL');
        $this->addSql('ALTER TABLE api_tokens ADD sender_address VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE api_tokens DROP sender_mode, DROP sender_address');

            return;
        }

        if (!$this->platform instanceof PostgreSQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE api_tokens DROP sender_mode');
        $this->addSql('ALTER TABLE api_tokens DROP sender_address');
    }
}

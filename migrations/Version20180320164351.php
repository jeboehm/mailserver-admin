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

class Version20180320164351 extends AbstractMigration
{
    private array $users = [];

    private array $aliases = [];

    public function preUp(Schema $schema): void
    {
        $this->fillUsers();
        $this->fillAliases();
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql('RENAME TABLE virtual_domains TO mail_domains');

        $this->addSql(
            'CREATE TABLE mail_aliases (id INT AUTO_INCREMENT NOT NULL, domain_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, destination VARCHAR(255) NOT NULL, INDEX IDX_5F12BB39115F0EE5 (domain_id), UNIQUE INDEX alias_idx (domain_id, name, destination), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'CREATE TABLE mail_users (id INT AUTO_INCREMENT NOT NULL, domain_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_1483A5E95E237E06 (name), INDEX IDX_1483A5E9115F0EE5 (domain_id), UNIQUE INDEX user_idx (name, domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'ALTER TABLE mail_aliases ADD CONSTRAINT FK_5F12BB39115F0EE5 FOREIGN KEY (domain_id) REFERENCES mail_domains (id)'
        );
        $this->addSql(
            'ALTER TABLE mail_users ADD CONSTRAINT FK_1483A5E9115F0EE5 FOREIGN KEY (domain_id) REFERENCES mail_domains (id)'
        );
        $this->addSql('DROP TABLE virtual_aliases');
        $this->addSql('DROP TABLE virtual_users');
    }

    public function postUp(Schema $schema): void
    {
        foreach ($this->users as $user) {
            $this->connection->insert('mail_users', $user);
        }

        foreach ($this->aliases as $alias) {
            $this->connection->insert('mail_aliases', $alias);
        }
    }

    private function fillUsers(): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->addSelect('email')
            ->addSelect('domain_id')
            ->addSelect('password')
            ->from('virtual_users');

        $result = $qb->fetchAllAssociative();

        foreach ($result as $row) {
            $this->users[] = [
                'name' => explode('@', $row['email'], 2)[0],
                'domain_id' => $row['domain_id'],
                'password' => $row['password'],
            ];
        }
    }

    private function fillAliases(): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->addSelect('source')
            ->addSelect('domain_id')
            ->addSelect('destination')
            ->from('virtual_aliases');

        $result = $qb->fetchAllAssociative();

        foreach ($result as $row) {
            $this->aliases[] = [
                'name' => explode('@', $row['source'], 2)[0],
                'domain_id' => $row['domain_id'],
                'destination' => $row['destination'],
            ];
        }
    }
}

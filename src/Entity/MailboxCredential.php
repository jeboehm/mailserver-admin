<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Service\MailboxCredential\MailboxCredentialKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A second way into a user's mailbox, which Dovecot's "mailbox_credentials" passdb reads.
 *
 * Maps the table for the schema tools. Short-lived credentials are not written through this entity:
 * their expiry has to come from the database clock, see App\Service\MailboxCredential\MailboxCredentials.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: MailboxCredential::TABLE)]
#[ORM\UniqueConstraint(name: 'mailbox_credential_name_idx', columns: ['user_id', 'name'])]
#[ORM\Index(name: 'mailbox_credential_expiry_idx', columns: ['expires_at'])]
class MailboxCredential
{
    public const string TABLE = 'mail_mailbox_credentials';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $secretHash;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: MailboxCredentialKind::class)]
    private MailboxCredentialKind $kind;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $allowImap;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $allowSmtp;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function getKind(): MailboxCredentialKind
    {
        return $this->kind;
    }

    public function allowsImap(): bool
    {
        return $this->allowImap;
    }

    public function allowsSmtp(): bool
    {
        return $this->allowSmtp;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

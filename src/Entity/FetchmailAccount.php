<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\FetchmailAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FetchmailAccountRepository::class)]
#[ORM\Table(name: 'fetchmail_accounts')]
#[ORM\UniqueConstraint(name: 'host_username_idx', columns: ['config_host', 'config_username'])]
#[UniqueEntity(fields: ['username', 'host'])]
class FetchmailAccount
{
    public ?bool $isSuccess = null;
    public ?\DateTimeInterface $lastRun = null;
    public ?string $lastLog = null;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'fetchmailAccounts')]
    private ?User $user = null;

    #[Assert\Hostname]
    #[Assert\NotBlank]
    #[ORM\Column(name: 'config_host', type: Types::STRING, options: ['collation' => 'utf8_unicode_ci'])]
    private string $host = '';

    #[Assert\Choice(choices: ['imap', 'pop3'])]
    #[Assert\NotBlank]
    #[ORM\Column(name: 'config_protocol', type: Types::STRING, length: 50, options: ['collation' => 'utf8_unicode_ci'])]
    private string $protocol = 'imap';

    #[Assert\Range(min: 1, max: 65535)]
    #[Assert\NotBlank]
    #[ORM\Column(name: 'config_port', type: Types::SMALLINT)]
    private int $port = 143;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(name: 'config_username', type: Types::STRING, options: ['collation' => 'utf8_unicode_ci'])]
    private string $username = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(name: 'config_password', type: Types::STRING, options: ['collation' => 'utf8_unicode_ci'])]
    private ?string $password = '';

    #[Assert\NotNull]
    #[ORM\Column(name: 'config_ssl', type: Types::BOOLEAN)]
    private bool $ssl = false;

    #[Assert\NotNull]
    #[ORM\Column(name: 'config_verify_ssl', type: Types::BOOLEAN)]
    private bool $verifySsl = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function isSsl(): bool
    {
        return $this->ssl;
    }

    public function setSsl(bool $ssl): void
    {
        $this->ssl = $ssl;
    }

    public function isVerifySsl(): bool
    {
        return $this->verifySsl;
    }

    public function setVerifySsl(bool $verifySsl): void
    {
        $this->verifySsl = $verifySsl;
    }
}

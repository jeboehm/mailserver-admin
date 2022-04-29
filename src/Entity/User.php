<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Serializable;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'mail_users')]
#[ORM\UniqueConstraint(name: 'user_idx', columns: ['name', 'domain_id'])]
#[UniqueEntity(['name', 'domain'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, Serializable, \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;
    #[Assert\NotNull]
    #[ORM\ManyToOne(targetEntity: 'Domain', inversedBy: 'users')]
    private ?Domain $domain = null;
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9\-\_.]{1,50}$/')]
    #[ORM\Column(type: 'string', name: 'name', options: ['collation' => 'utf8_unicode_ci'])]
    private string $name = '';
    #[ORM\Column(type: 'string', name: 'password', options: ['collation' => 'utf8_unicode_ci'])]
    private string $password = '';
    #[Assert\Length(min: 6, max: 5000)]
    private ?string $plainPassword = null;
    #[ORM\Column(type: 'boolean', name: 'admin')]
    private bool $admin = false;
    #[ORM\Column(type: 'boolean', name: 'enabled')]
    private bool $enabled = true;
    #[ORM\Column(type: 'boolean', name: 'send_only')]
    private bool $sendOnly = false;
    #[Assert\Range(min: 0)]
    #[Assert\NotBlank]
    #[ORM\Column(type: 'integer', name: 'quota')]
    private int $quota = 0;
    private ?string $domainName = null;

    public function __toString(): string
    {
        if (null !== $this->domain) {
            return sprintf('%s@%s', $this->name, $this->domain->getName());
        }

        if (null !== $this->domainName) {
            return sprintf('%s@%s', $this->name, $this->domainName);
        }

        return '';
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(Domain $domain): void
    {
        $this->domain = $domain;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getRoles(): array
    {
        if ($this->admin) {
            return ['ROLE_ADMIN', 'ROLE_USER'];
        }

        return ['ROLE_USER'];
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): void
    {
        $this->admin = $admin;
    }

    public function getSalt(): string
    {
        $parts = explode('$', $this->password, 5);

        return $parts[3] ?? '';
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = '';
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): void
    {
        $this->plainPassword = $plainPassword;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getSendOnly(): bool
    {
        return $this->sendOnly;
    }

    public function setSendOnly(bool $sendOnly): void
    {
        $this->sendOnly = $sendOnly;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
    }

    public function serialize(): string
    {
        return serialize([$this->id, $this->password, $this->domain->getName(), $this->admin, $this->name]);
    }

    public function unserialize($serialized): void
    {
        [$this->id, $this->password, $this->domainName, $this->admin, $this->name] = unserialize($serialized, ['allowed_classes' => false]);
    }

    public function getUserIdentifier(): string
    {
        return (string) $this;
    }
}

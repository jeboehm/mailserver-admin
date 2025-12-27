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
use App\Service\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'mail_users')]
#[ORM\UniqueConstraint(name: 'user_idx', columns: ['name', 'domain_id'])]
#[UniqueEntity(['name', 'domain'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;
    #[Assert\NotNull]
    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'users')]
    private ?Domain $domain = null;
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9\-\_.]{1,50}$/')]
    #[ORM\Column(name: 'name', type: Types::STRING, options: ['collation' => 'utf8_unicode_ci'])]
    private string $name = '';
    #[ORM\Column(name: 'password', type: Types::STRING, options: ['collation' => 'utf8_unicode_ci'])]
    private string $password = '';
    #[Assert\Length(min: 6, max: 5000)]
    private ?string $plainPassword = null;
    #[ORM\Column(name: 'admin', type: Types::BOOLEAN)]
    private bool $admin = false;
    #[ORM\Column(name: 'domain_admin', type: Types::BOOLEAN)]
    private bool $domainAdmin = false;
    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN)]
    private bool $enabled = true;
    #[ORM\Column(name: 'send_only', type: Types::BOOLEAN)]
    private bool $sendOnly = false;
    #[Assert\Range(min: 0)]
    #[Assert\NotBlank]
    #[ORM\Column(name: 'quota', type: Types::INTEGER)]
    private int $quota = 0;
    private ?string $domainName = null;

    /**
     * @var Collection<int, FetchmailAccount>
     */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: FetchmailAccount::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $fetchmailAccounts;

    public function __construct()
    {
        $this->fetchmailAccounts = new ArrayCollection();
    }

    #[\Override]
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

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function __serialize(): array
    {
        return [$this->id, $this->password, $this->domain->getName(), $this->admin, $this->domainAdmin, $this->name];
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function __unserialize(array $data): void
    {
        [$this->id, $this->password, $this->domainName, $this->admin, $this->domainAdmin, $this->name] = $data;
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

    #[\Override]
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    #[\Override]
    public function getRoles(): array
    {
        if ($this->admin) {
            return [Roles::ROLE_ADMIN];
        }

        if ($this->domainAdmin) {
            return [Roles::ROLE_DOMAIN_ADMIN];
        }

        return [Roles::ROLE_USER];
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): void
    {
        $this->admin = $admin;
    }

    public function isDomainAdmin(): bool
    {
        return $this->domainAdmin;
    }

    public function setDomainAdmin(bool $domainAdmin): void
    {
        $this->domainAdmin = $domainAdmin;
    }

    #[\Override]
    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
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

    #[\Override]
    public function getUserIdentifier(): string
    {
        return (string) $this;
    }

    /**
     * @return Collection<int, FetchmailAccount>
     */
    public function getFetchmailAccounts(): Collection
    {
        return $this->fetchmailAccounts;
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 * @ORM\Table(name="mail_users", uniqueConstraints={@ORM\UniqueConstraint(name="user_idx", columns={"name", "domain_id"})})
 * @UniqueEntity({"name", "domain"})
 */
class User implements UserInterface
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Domain", inversedBy="users")
     * @Assert\NotNull()
     */
    private ?Domain $domain = null;

    /**
     * @ORM\Column(type="string", name="name", options={"collation":"utf8_unicode_ci"})
     * @Assert\NotBlank()
     * @Assert\Regex(pattern="/^[a-z0-9\-\_.]{1,50}$/")
     */
    private string $name = '';

    /**
     * @ORM\Column(type="string", name="password", options={"collation":"utf8_unicode_ci"})
     */
    private string $password = '';

    /**
     * @Assert\Length(min="6", max="5000")
     */
    private ?string $plainPassword = null;

    /**
     * @ORM\Column(type="boolean", name="admin")
     */
    private bool $admin = false;

    /**
     * @ORM\Column(type="boolean", name="enabled")
     */
    private bool $enabled = true;

    /**
     * @ORM\Column(type="boolean", name="send_only")
     */
    private bool $sendOnly = false;

    /**
     * @ORM\Column(type="integer", name="quota")
     * @Assert\Range(min="0")
     * @Assert\NotBlank()
     */
    private int $quota = 0;

    private array $roles = ['ROLE_USER'];

    public function __toString(): string
    {
        if (null !== $this->getDomain()) {
            return sprintf('%s@%s', $this->name, $this->getDomain()->getName());
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
        return $this->roles;
    }

    public function addRole(string $role): void
    {
        $this->roles[] = $role;
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

    public function getUsername(): string
    {
        return (string) $this;
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
}

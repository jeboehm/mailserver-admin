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
use Serializable;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 * @ORM\Table(name="virtual_users")
 * @UniqueEntity("email")
 */
class User implements UserInterface, Serializable
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="Domain", inversedBy="users")
     * @Assert\NotNull()
     */
    private $domain;

    /**
     * @ORM\Column(type="string", name="email", unique=true)
     * @Assert\NotBlank()
     * @Assert\Email()
     */
    private $email = '';

    /**
     * @ORM\Column(type="string", name="password")
     */
    private $password = '';

    /**
     * @Assert\Length(min="6", max="5000")
     */
    private $plainPassword;

    private $roles = ['ROLE_USER'];

    public function __toString(): string
    {
        return $this->email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(Domain $domain): void
    {
        $this->domain = $domain;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail($email): void
    {
        $this->email = mb_strtolower($email);
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

    public function getSalt(): string
    {
        return explode('$', $this->password, 5)[3];
    }

    public function getUsername(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = '';
    }

    public function serialize(): string
    {
        return serialize([$this->id, $this->email, $this->password, $this->roles]);
    }

    public function unserialize($serialized): void
    {
        [
            $this->id,
            $this->email,
            $this->password,
            $this->roles,
        ] = unserialize($serialized, ['allowed_classes' => false]);
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): void
    {
        $this->plainPassword = $plainPassword;
    }
}

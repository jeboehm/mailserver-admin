<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\DomainRepository")
 * @ORM\Table(name="mail_domains")
 * @UniqueEntity("name")
 */
class Domain
{
    use DkimInfoTrait;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer", name="id")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", name="name", unique=true, options={"collation":"utf8_unicode_ci"})
     * @Assert\NotBlank()
     */
    private string $name = '';

    /**
     * @ORM\Column(type="boolean", name="dkim_enabled")
     */
    private bool $dkimEnabled = false;

    /**
     * @ORM\Column(type="string", name="dkim_selector")
     * @Assert\Regex(pattern="/^[a-z0-9]{1,50}$/")
     */
    private string $dkimSelector = '';

    /**
     * @ORM\Column(type="text", name="dkim_private_key")
     */
    private string $dkimPrivateKey = '';

    /**
     * @ORM\OneToMany(targetEntity="User", mappedBy="domain", cascade={"persist", "remove"}, orphanRemoval=true)
     * @Assert\Valid()
     */
    private $users;

    /**
     * @ORM\OneToMany(targetEntity="Alias", mappedBy="domain", cascade={"persist", "remove"}, orphanRemoval=true)
     * @Assert\Valid()
     */
    private $aliases;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->aliases = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
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

    public function getDkimEnabled(): bool
    {
        return $this->dkimEnabled;
    }

    public function setDkimEnabled(bool $dkimEnabled): void
    {
        $this->dkimEnabled = $dkimEnabled;
    }

    public function getDkimSelector(): string
    {
        return $this->dkimSelector;
    }

    public function setDkimSelector(string $dkimSelector): void
    {
        $this->dkimSelector = $dkimSelector;
    }

    public function getDkimPrivateKey(): string
    {
        return $this->dkimPrivateKey;
    }

    public function setDkimPrivateKey(string $dkimPrivateKey): void
    {
        $this->dkimPrivateKey = $dkimPrivateKey;
    }

    public function getUsers(): iterable
    {
        return $this->users;
    }

    public function getAliases(): iterable
    {
        return $this->aliases;
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use App\Repository\DomainRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'mail_domains')]
#[UniqueEntity(fields: ['name'])]
class Domain implements \Stringable
{
    use DkimInfoTrait;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;
    #[Assert\NotBlank]
    #[ORM\Column(name: 'name', type: 'string', unique: true, options: ['collation' => 'utf8_unicode_ci'])]
    private string $name = '';
    #[ORM\Column(name: 'dkim_enabled', type: 'boolean')]
    private bool $dkimEnabled = false;
    #[ORM\Column(name: 'dkim_selector', type: 'string')]
    private string $dkimSelector = 'dkim';
    #[ORM\Column(name: 'dkim_private_key', type: 'text')]
    private string $dkimPrivateKey = '';
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: 'User', mappedBy: 'domain', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $users;
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: 'Alias', mappedBy: 'domain', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $aliases;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->aliases = new ArrayCollection();
    }

    #[\Override]
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

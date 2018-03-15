<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\DomainRepository")
 * @ORM\Table(name="virtual_domains")
 * @UniqueEntity("name")
 */
class Domain
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer", name="id")
     */
    private $id;

    /**
     * @ORM\Column(type="string", name="name", unique=true)
     * @Assert\NotBlank()
     */
    private $name;

    /**
     * @ORM\OneToMany(targetEntity="User", mappedBy="domain", cascade={"persist", "remove"})
     * @Assert\Valid()
     */
    private $users;

    /**
     * @ORM\OneToMany(targetEntity="Alias", mappedBy="domain", cascade={"persist", "remove"})
     * @Assert\Valid()
     */
    private $aliases;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->aliases = new ArrayCollection();
    }

    public function getId(): int
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

    public function getUsers(): iterable
    {
        return $this->users;
    }

    public function getAliases(): iterable
    {
        return $this->aliases;
    }
}

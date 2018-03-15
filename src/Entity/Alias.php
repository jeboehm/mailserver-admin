<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\AliasRepository")
 * @ORM\Table(name="virtual_aliases")
 * @UniqueEntity(fields={"source", "destination"})
 */
class Alias
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="Domain", inversedBy="aliases")
     */
    private $domain;

    /**
     * @ORM\Column(type="string", name="source")
     * @Assert\NotBlank()
     * @Assert\Email()
     */
    private $source;

    /**
     * @ORM\Column(type="string", name="destination")
     * @Assert\NotBlank()
     * @Assert\Email()
     */
    private $destination;

    public function __construct(Domain $domain)
    {
        $this->domain = $domain;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }
}

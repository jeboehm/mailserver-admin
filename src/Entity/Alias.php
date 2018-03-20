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
     * @Assert\NotNull()
     */
    private $domain;

    /**
     * @ORM\Column(type="string", name="source")
     * @Assert\NotBlank()
     * @Assert\Email()
     */
    private $source = '';

    /**
     * @ORM\Column(type="string", name="destination")
     * @Assert\NotBlank()
     * @Assert\Email()
     */
    private $destination = '';

    public function __toString(): string
    {
        return sprintf('%s → %s', $this->source, $this->destination);
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

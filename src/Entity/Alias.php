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
 * @ORM\Table(name="mail_aliases", uniqueConstraints={@ORM\UniqueConstraint(name="alias_idx", columns={"domain_id", "name", "destination"})})
 * @UniqueEntity(fields={"destination", "name", "domain"})
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
     * @ORM\Column(type="string", name="name", options={"collation":"utf8_unicode_ci"})
     * @Assert\NotBlank()
     * @Assert\Regex(pattern="/^[a-z0-9\-\_.]{1,50}$/")
     */
    private $name = '';

    /**
     * @ORM\Column(type="string", name="destination", options={"collation":"utf8_unicode_ci"})
     * @Assert\NotBlank()
     * @Assert\Email()
     */
    private $destination = '';

    public function __toString(): string
    {
        if ($this->getDomain()) {
            return sprintf('%s@%s → %s', $this->name, $this->getDomain()->getName(), $this->destination);
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

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }
}

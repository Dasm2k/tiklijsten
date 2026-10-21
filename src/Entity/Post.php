<?php

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\ORM\Mapping as ORM;

/** Een kazerne/post van de zone. Gedeeld over alle afdelingen. */
#[ORM\Entity(repositoryClass: PostRepository::class)]
class Post
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 128)]
    private string $naam = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $straat = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $huisnummer = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $gemeente = null;

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function getStraat(): ?string { return $this->straat; }
    public function setStraat(?string $straat): static { $this->straat = $straat; return $this; }
    public function getHuisnummer(): ?string { return $this->huisnummer; }
    public function setHuisnummer(?string $h): static { $this->huisnummer = $h; return $this; }
    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $p): static { $this->postcode = $p; return $this; }
    public function getGemeente(): ?string { return $this->gemeente; }
    public function setGemeente(?string $g): static { $this->gemeente = $g; return $this; }
    public function __toString(): string { return $this->naam; }
}

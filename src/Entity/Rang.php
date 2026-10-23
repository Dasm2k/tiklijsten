<?php

namespace App\Entity;

use App\Repository\RangRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RangRepository::class)]
class Rang
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Hoe hoger, hoe hoger de rang (zelfde waarden als bw_rangen.rang). */
    #[ORM\Column(unique: true)]
    private int $niveau = 0;

    #[ORM\Column(length: 32)]
    private string $kort = '';

    #[ORM\Column(length: 128)]
    private string $naam = '';

    public function getId(): ?int { return $this->id; }
    public function getNiveau(): int { return $this->niveau; }
    public function setNiveau(int $niveau): static { $this->niveau = $niveau; return $this; }
    public function getKort(): string { return $this->kort; }
    public function setKort(string $kort): static { $this->kort = $kort; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function __toString(): string { return $this->kort; }
}

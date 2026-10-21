<?php

namespace App\Entity;

use App\Repository\GemeenteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GemeenteRepository::class)]
class Gemeente
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $naam = '';

    #[ORM\Column]
    private bool $actief = true;

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function isActief(): bool { return $this->actief; }
    public function setActief(bool $actief): static { $this->actief = $actief; return $this; }
    public function __toString(): string { return $this->naam; }
}

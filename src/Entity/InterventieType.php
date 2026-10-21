<?php

namespace App\Entity;

use App\Repository\InterventieTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InterventieTypeRepository::class)]
class InterventieType
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $naam = '';

    #[ORM\Column]
    private int $volgorde = 0;

    #[ORM\Column]
    private bool $actief = true;

    /** Telt mee in de jaarstatistiek per peloton (vroeger: interventie-id < 14). */
    #[ORM\Column]
    private bool $inStatistiek = true;

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function getVolgorde(): int { return $this->volgorde; }
    public function setVolgorde(int $volgorde): static { $this->volgorde = $volgorde; return $this; }
    public function isActief(): bool { return $this->actief; }
    public function setActief(bool $actief): static { $this->actief = $actief; return $this; }
    public function isInStatistiek(): bool { return $this->inStatistiek; }
    public function setInStatistiek(bool $v): static { $this->inStatistiek = $v; return $this; }
    public function __toString(): string { return $this->naam; }
}

<?php

namespace App\Entity;

use App\Repository\MateriaalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MateriaalRepository::class)]
class Materiaal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $naam = '';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $keuring = null;

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(?string $naam): static { $this->naam = (string) $naam; return $this; }
    public function getKeuring(): ?\DateTimeImmutable { return $this->keuring; }
    public function setKeuring(?\DateTimeImmutable $k): static { $this->keuring = $k; return $this; }
    public function __toString(): string { return $this->naam; }
}

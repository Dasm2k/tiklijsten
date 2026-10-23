<?php

namespace App\Entity;

use App\Repository\VoertuigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VoertuigRepository::class)]
class Voertuig
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $naam = '';

    #[ORM\Column(length: 128)]
    private string $roepnaam = '';

    #[ORM\Column(length: 64)]
    private string $nummerplaat = '';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $keuring = null;

    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Post $post = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actief = true;

    public function getPost(): ?Post { return $this->post; }
    public function setPost(?Post $post): static { $this->post = $post; return $this; }
    public function isActief(): bool { return $this->actief; }
    public function setActief(bool $actief): static { $this->actief = $actief; return $this; }
    public function getChauffeurLabel(): string
    {
        return implode(' · ', array_filter([$this->roepnaam, $this->naam, $this->nummerplaat]));
    }

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(?string $naam): static { $this->naam = (string) $naam; return $this; }
    public function getRoepnaam(): string { return $this->roepnaam; }
    public function setRoepnaam(?string $r): static { $this->roepnaam = (string) $r; return $this; }
    public function getNummerplaat(): string { return $this->nummerplaat; }
    public function setNummerplaat(?string $n): static { $this->nummerplaat = (string) $n; return $this; }
    public function getKeuring(): ?\DateTimeImmutable { return $this->keuring; }
    public function setKeuring(?\DateTimeImmutable $k): static { $this->keuring = $k; return $this; }
    public function __toString(): string { return $this->naam; }
}

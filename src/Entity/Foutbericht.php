<?php

namespace App\Entity;

use App\Enum\FoutStatus;
use App\Repository\FoutberichtRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FoutberichtRepository::class)]
class Foutbericht
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'berichten'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Foutmelding $foutmelding = null;

    #[ORM\Column]
    private \DateTimeImmutable $datum;

    #[ORM\Column(enumType: FoutStatus::class)]
    private FoutStatus $status = FoutStatus::NIEUW;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Persoon $auteur = null;

    #[ORM\Column(type: 'text')]
    private string $bericht = '';

    public function __construct()
    {
        $this->datum = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getFoutmelding(): ?Foutmelding { return $this->foutmelding; }
    public function setFoutmelding(?Foutmelding $f): static { $this->foutmelding = $f; return $this; }
    public function getDatum(): \DateTimeImmutable { return $this->datum; }
    public function setDatum(\DateTimeImmutable $d): static { $this->datum = $d; return $this; }
    public function getStatus(): FoutStatus { return $this->status; }
    public function setStatus(FoutStatus $s): static { $this->status = $s; return $this; }
    public function getAuteur(): ?Persoon { return $this->auteur; }
    public function setAuteur(?Persoon $a): static { $this->auteur = $a; return $this; }
    public function getBericht(): string { return $this->bericht; }
    public function setBericht(string $b): static { $this->bericht = $b; return $this; }
}

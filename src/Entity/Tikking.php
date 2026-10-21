<?php

namespace App\Entity;

use App\Repository\TikkingRepository;
use Doctrine\ORM\Mapping as ORM;

/** Iemand die ingetikt heeft op een lijst (vroeger bw_aanwezigen). */
#[ORM\Entity(repositoryClass: TikkingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tikking', columns: ['lijst_id', 'persoon_id'])]
#[ORM\Index(name: 'idx_tikking_persoon', columns: ['persoon_id', 'einde'])]
class Tikking
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tikkingen'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Aanwezigheidslijst $lijst = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Persoon $persoon = null;

    /** Eigen beginuur (tweede beginuur van de lijst); null = beginuur van de lijst. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $start = null;

    /** Moment van intikken = einde van de prestatie. */
    #[ORM\Column]
    private ?\DateTimeImmutable $einde = null;

    public function getId(): ?int { return $this->id; }
    public function getLijst(): ?Aanwezigheidslijst { return $this->lijst; }
    public function setLijst(?Aanwezigheidslijst $lijst): static { $this->lijst = $lijst; return $this; }
    public function getPersoon(): ?Persoon { return $this->persoon; }
    public function setPersoon(Persoon $persoon): static { $this->persoon = $persoon; return $this; }
    public function getEigenStart(): ?\DateTimeImmutable { return $this->start; }
    public function setStart(?\DateTimeImmutable $start): static { $this->start = $start; return $this; }
    public function getEinde(): ?\DateTimeImmutable { return $this->einde; }
    public function setEinde(\DateTimeImmutable $einde): static { $this->einde = $einde; return $this; }

    public function getStart(): ?\DateTimeImmutable
    {
        return $this->start ?? $this->lijst?->getStart();
    }

    /** Prestatie in seconden. */
    public function getPrestatie(): int
    {
        $start = $this->getStart();
        if (!$start || !$this->einde) {
            return 0;
        }
        return max(0, $this->einde->getTimestamp() - $start->getTimestamp());
    }
}

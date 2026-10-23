<?php

namespace App\Entity;

use App\Repository\HerinneringRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Controlevraag die op de afgedrukte lijst verschijnt (vroeger bw_reminders). */
#[ORM\Entity(repositoryClass: HerinneringRepository::class)]
class Herinnering
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $vraag = '';

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getVraag(): string { return $this->vraag; }
    public function setVraag(?string $vraag): static { $this->vraag = (string) $vraag; return $this; }
    public function __toString(): string { return $this->vraag; }
}

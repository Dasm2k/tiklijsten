<?php

namespace App\Entity;

use App\Enum\FoutStatus;
use App\Enum\FoutType;
use App\Repository\FoutmeldingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FoutmeldingRepository::class)]
#[ORM\Index(name: 'idx_fout_status', columns: ['afdeling_id', 'status'])]
class Foutmelding
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(enumType: FoutType::class)]
    private FoutType $type = FoutType::ANDERS;

    /** Waarover gaat het: voertuignaam, toestel, ... */
    #[ORM\Column(length: 128)]
    private string $wat = '';

    #[ORM\Column(type: 'text')]
    private string $probleem = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Persoon $melder = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Persoon $opvolger = null;

    #[ORM\Column]
    private \DateTimeImmutable $aangemaakt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $afgesloten = null;

    #[ORM\Column(enumType: FoutStatus::class)]
    private FoutStatus $status = FoutStatus::NIEUW;

    #[ORM\Column]
    private bool $iedereenMailen = false;

    /** @var Collection<int, Foutbericht> */
    #[ORM\OneToMany(targetEntity: Foutbericht::class, mappedBy: 'foutmelding', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $berichten;

    public function __construct()
    {
        $this->aangemaakt = new \DateTimeImmutable();
        $this->berichten = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getType(): FoutType { return $this->type; }
    public function setType(FoutType $type): static { $this->type = $type; return $this; }
    public function getWat(): string { return $this->wat; }
    public function setWat(string $wat): static { $this->wat = mb_substr($wat, 0, 128); return $this; }
    public function getProbleem(): string { return $this->probleem; }
    public function setProbleem(string $probleem): static { $this->probleem = $probleem; return $this; }
    public function getMelder(): ?Persoon { return $this->melder; }
    public function setMelder(?Persoon $melder): static { $this->melder = $melder; return $this; }
    public function getOpvolger(): ?Persoon { return $this->opvolger; }
    public function setOpvolger(?Persoon $opvolger): static { $this->opvolger = $opvolger; return $this; }
    public function getAangemaakt(): \DateTimeImmutable { return $this->aangemaakt; }
    public function setAangemaakt(\DateTimeImmutable $d): static { $this->aangemaakt = $d; return $this; }
    public function getAfgesloten(): ?\DateTimeImmutable { return $this->afgesloten; }
    public function setAfgesloten(?\DateTimeImmutable $d): static { $this->afgesloten = $d; return $this; }
    public function getStatus(): FoutStatus { return $this->status; }
    public function setStatus(FoutStatus $status): static { $this->status = $status; return $this; }
    public function isIedereenMailen(): bool { return $this->iedereenMailen; }
    public function setIedereenMailen(bool $v): static { $this->iedereenMailen = $v; return $this; }

    /** @return Collection<int, Foutbericht> */
    public function getBerichten(): Collection { return $this->berichten; }

    public function addBericht(Foutbericht $bericht): static
    {
        if (!$this->berichten->contains($bericht)) {
            $this->berichten->add($bericht);
            $bericht->setFoutmelding($this);
        }
        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\AanwezigheidslijstRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Een aanwezigheidslijst voor één interventie (vroeger bw_aanwezigheidslijsten). */
#[ORM\Entity(repositoryClass: AanwezigheidslijstRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_lijst_nummer', columns: ['afdeling_id', 'nummer'])]
#[ORM\Index(name: 'idx_lijst_status', columns: ['afdeling_id', 'afgesloten', 'gearchiveerd'])]
#[ORM\Index(name: 'idx_lijst_start', columns: ['afdeling_id', 'start'])]
class Aanwezigheidslijst
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    /** Zichtbaar lijstnummer "#1234" (bij import = oud id). */
    #[ORM\Column]
    private int $nummer = 0;

    /** Volgnummer binnen het peloton (oude "pid"). */
    #[ORM\Column(nullable: true)]
    private ?int $volgnummer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Post $post = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\LessThanOrEqual('+5 minutes', message: 'Datum en uur liggen in de toekomst.')]
    private ?\DateTimeImmutable $start = null;

    /** Optioneel tweede beginuur (bv. tweede uitruk). */
    #[ORM\Column(nullable: true)]
    #[Assert\LessThanOrEqual('+5 minutes', message: 'Datum en uur liggen in de toekomst.')]
    private ?\DateTimeImmutable $tweedeStart = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'Kies een gemeente.')]
    private ?Gemeente $gemeente = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'Kies een interventie.')]
    private ?InterventieType $interventieType = null;

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $info = '';

    #[ORM\Column]
    #[Assert\Positive(message: 'Kies een peloton.')]
    private int $peloton = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'Kies een bevelvoerder.')]
    private ?Persoon $bevelvoerder = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Persoon $centralist = null;

    #[ORM\Column]
    private bool $afgesloten = false;

    #[ORM\Column]
    private bool $gearchiveerd = false;

    /** @var Collection<int, Tikking> */
    #[ORM\OneToMany(targetEntity: Tikking::class, mappedBy: 'lijst', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['einde' => \SortDirection::Descending])]
    private Collection $tikkingen;

    public function __construct()
    {
        $this->tikkingen = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getNummer(): int { return $this->nummer; }
    public function setNummer(int $nummer): static { $this->nummer = $nummer; return $this; }
    public function getVolgnummer(): ?int { return $this->volgnummer; }
    public function setVolgnummer(?int $v): static { $this->volgnummer = $v; return $this; }
    public function getPost(): ?Post { return $this->post; }
    public function setPost(?Post $post): static { $this->post = $post; return $this; }
    public function getStart(): ?\DateTimeImmutable { return $this->start; }
    public function setStart(?\DateTimeImmutable $start): static { $this->start = $start; return $this; }
    public function getTweedeStart(): ?\DateTimeImmutable { return $this->tweedeStart; }
    public function setTweedeStart(?\DateTimeImmutable $d): static { $this->tweedeStart = $d; return $this; }
    public function getGemeente(): ?Gemeente { return $this->gemeente; }
    public function setGemeente(?Gemeente $g): static { $this->gemeente = $g; return $this; }
    public function getInterventieType(): ?InterventieType { return $this->interventieType; }
    public function setInterventieType(?InterventieType $t): static { $this->interventieType = $t; return $this; }
    public function getInfo(): string { return $this->info; }
    public function setInfo(?string $info): static { $this->info = (string) $info; return $this; }
    public function getPeloton(): int { return $this->peloton; }
    public function setPeloton(int $peloton): static { $this->peloton = $peloton; return $this; }
    public function getBevelvoerder(): ?Persoon { return $this->bevelvoerder; }
    public function setBevelvoerder(?Persoon $p): static { $this->bevelvoerder = $p; return $this; }
    public function getCentralist(): ?Persoon { return $this->centralist; }
    public function setCentralist(?Persoon $p): static { $this->centralist = $p; return $this; }
    public function isAfgesloten(): bool { return $this->afgesloten; }
    public function setAfgesloten(bool $afgesloten): static { $this->afgesloten = $afgesloten; return $this; }
    public function isGearchiveerd(): bool { return $this->gearchiveerd; }
    public function setGearchiveerd(bool $g): static { $this->gearchiveerd = $g; return $this; }

    /** @return Collection<int, Tikking> */
    public function getTikkingen(): Collection { return $this->tikkingen; }

    public function addTikking(Tikking $tikking): static
    {
        if (!$this->tikkingen->contains($tikking)) {
            $this->tikkingen->add($tikking);
            $tikking->setLijst($this);
        }
        return $this;
    }

    public function removeTikking(Tikking $tikking): static
    {
        $this->tikkingen->removeElement($tikking);
        return $this;
    }

    public function heeftGetikt(Persoon $persoon): bool
    {
        foreach ($this->tikkingen as $t) {
            if ($t->getPersoon()?->getId() === $persoon->getId()) {
                return true;
            }
        }
        return false;
    }

    /** "#32933 (1204)" */
    public function getLabel(): string
    {
        return '#'.$this->nummer.($this->volgnummer ? ' ('.$this->volgnummer.')' : '');
    }
}

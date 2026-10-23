<?php

namespace App\Entity;

use App\Repository\MailinglijstRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MailinglijstRepository::class)]
class Mailinglijst
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $naam = '';

    #[ORM\Column(type: 'text')]
    private string $omschrijving = '';

    /** Ontvangt de afgesloten lijsten van dit peloton (vroeger mailing-id 1..6). */
    #[ORM\Column(nullable: true)]
    private ?int $peloton = null;

    /** Ontvangt elke afgesloten lijst, ongeacht het peloton. */
    #[ORM\Column]
    private bool $alleLijsten = false;

    /** Ontvangt nieuwe foutmeldingen (vroeger mailing-id 7). */
    #[ORM\Column]
    private bool $foutmeldingen = false;

    /** @var Collection<int, Persoon> */
    #[ORM\ManyToMany(targetEntity: Persoon::class)]
    #[ORM\JoinTable(name: 'mailinglijst_lid')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['naam' => \SortDirection::Ascending, 'voornaam' => \SortDirection::Ascending])]
    private Collection $leden;

    public function __construct()
    {
        $this->leden = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function getOmschrijving(): string { return $this->omschrijving; }
    public function setOmschrijving(?string $o): static { $this->omschrijving = (string) $o; return $this; }
    public function getPeloton(): ?int { return $this->peloton; }
    public function setPeloton(?int $peloton): static { $this->peloton = $peloton; return $this; }
    public function isAlleLijsten(): bool { return $this->alleLijsten; }
    public function setAlleLijsten(bool $v): static { $this->alleLijsten = $v; return $this; }
    public function isFoutmeldingen(): bool { return $this->foutmeldingen; }
    public function setFoutmeldingen(bool $v): static { $this->foutmeldingen = $v; return $this; }

    /** Systeemlijsten (gekoppeld aan een functie) mogen niet verwijderd worden. */
    public function isSysteemlijst(): bool
    {
        return null !== $this->peloton || $this->alleLijsten || $this->foutmeldingen;
    }

    /** @return Collection<int, Persoon> */
    public function getLeden(): Collection { return $this->leden; }

    public function addLid(Persoon $p): static
    {
        if (!$this->leden->contains($p)) {
            $this->leden->add($p);
        }
        return $this;
    }

    public function removeLid(Persoon $p): static
    {
        $this->leden->removeElement($p);
        return $this;
    }

    /** @return string[] */
    public function getEmailadressen(): array
    {
        $adressen = [];
        foreach ($this->leden as $lid) {
            if ($lid->isActief() && $lid->getEmail()) {
                $adressen[] = $lid->getEmail();
            }
        }
        return array_values(array_unique($adressen));
    }
}

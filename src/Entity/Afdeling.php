<?php

namespace App\Entity;

use App\Repository\AfdelingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Een afdeling met een eigen tiksysteem (vroeger een aparte installatie + database):
 * bv. Post Boom/Rumst, Post Niel, Jeugdbrandweer.
 */
#[ORM\Entity(repositoryClass: AfdelingRepository::class)]
#[UniqueEntity('slug')]
class Afdeling
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank, Assert\Regex('/^[a-z0-9-]+$/')]
    private string $slug = '';

    /** Getoond op de portaalpagina, bv. "Post Boom/Rumst". */
    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $naam = '';

    /** Titel in de browser en in e-mails, bv. "Brandweer Boom". */
    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $titel = '';

    #[ORM\Column(length: 255)]
    private string $footer = '';

    /** Automatisch afmelden na zoveel minuten inactiviteit (0 = nooit). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $sessieduur = 0;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 9)]
    private int $aantalPelotons = 5;

    #[ORM\Column]
    private bool $foutmeldingenActief = true;

    #[ORM\Column]
    private bool $voertuigenActief = true;

    /** Komma-gescheiden e-mailadressen voor meldingen van kadavers. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $kadaverOntvangers = null;

    #[ORM\Column]
    private int $volgorde = 0;

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function getTitel(): string { return $this->titel; }
    public function setTitel(string $titel): static { $this->titel = $titel; return $this; }
    public function getFooter(): string { return $this->footer; }
    public function setFooter(?string $footer): static { $this->footer = (string) $footer; return $this; }
    public function getSessieduur(): int { return $this->sessieduur; }
    public function setSessieduur(int $sessieduur): static { $this->sessieduur = $sessieduur; return $this; }
    public function getAantalPelotons(): int { return $this->aantalPelotons; }
    public function setAantalPelotons(int $aantalPelotons): static { $this->aantalPelotons = $aantalPelotons; return $this; }
    public function isFoutmeldingenActief(): bool { return $this->foutmeldingenActief; }
    public function setFoutmeldingenActief(bool $v): static { $this->foutmeldingenActief = $v; return $this; }
    public function isVoertuigenActief(): bool { return $this->voertuigenActief; }
    public function setVoertuigenActief(bool $v): static { $this->voertuigenActief = $v; return $this; }
    public function getKadaverOntvangers(): ?string { return $this->kadaverOntvangers; }
    public function setKadaverOntvangers(?string $v): static { $this->kadaverOntvangers = $v; return $this; }
    public function getVolgorde(): int { return $this->volgorde; }
    public function setVolgorde(int $volgorde): static { $this->volgorde = $volgorde; return $this; }

    /** @return int[] */
    public function getPelotons(): array
    {
        return range(1, $this->aantalPelotons);
    }

    /** @return string[] */
    public function getKadaverOntvangersLijst(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->kadaverOntvangers))));
    }

    public function __toString(): string { return $this->naam; }
}

<?php

namespace App\Entity;

use App\Enum\Rol;
use App\Repository\PersoonRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Personeelslid (vroeger bw_users). Meldt aan en tikt in met tiknummer + code.
 * Ook de tik-pc's van de posten zijn personen, met rol POST.
 */
#[ORM\Entity(repositoryClass: PersoonRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_persoon_tiknummer', columns: ['afdeling_id', 'tiknummer'])]
#[ORM\Index(name: 'idx_persoon_naam', columns: ['naam', 'voornaam'])]
#[UniqueEntity(fields: ['afdeling', 'tiknummer'], message: 'Dit tiknummer is al in gebruik.')]
class Persoon implements UserInterface, PasswordAuthenticatedUserInterface
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\Column]
    #[Assert\NotBlank, Assert\Positive]
    private ?int $tiknummer = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $zonenummer = null;

    /** Gehashte code (bcrypt; md5 voor nog niet gemigreerde codes uit de oude toepassing). */
    #[ORM\Column(length: 255)]
    private string $code = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Rang $rang = null;

    /** Peloton 1..n, null = niet ingedeeld. */
    #[ORM\Column(nullable: true)]
    private ?int $peloton = null;

    /** Post van het personeelslid; voor een tik-pc de post die hij vertegenwoordigt. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Post $post = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $naam = '';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $voornaam = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column]
    private bool $actief = true;

    #[ORM\Column]
    private bool $beroeps = false;

    #[ORM\Column(length: 20, enumType: Rol::class)]
    private Rol $rol = Rol::LID;

    /** Persoonlijke sessieduur in minuten; null = instelling van de afdeling, 0 = nooit afmelden. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $sessieduur = null;

    /** Enkel voor tik-pc's: lijsten van deze post horen altijd bij dit peloton (null = kiezen bij aanmaken). */
    #[ORM\Column(nullable: true)]
    private ?int $vastPeloton = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $laatstGezien = null;

    /** Technisch account (administrator, tik-pc): niet in personeelslijsten, kan niet intikken. */
    #[ORM\Column]
    private bool $systeemaccount = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(nullable: true)]
    private ?int $totpLastStep = null;

    public function isSuperadmin(): bool { return $this->rol === Rol::SUPERADMIN; }
    public function getTotpSecret(): ?string { return $this->totpSecret; }
    public function setTotpSecret(?string $secret): static { $this->totpSecret = $secret; $this->totpLastStep = null; return $this; }
    public function getTotpLastStep(): ?int { return $this->totpLastStep; }
    public function setTotpLastStep(int $step): static { $this->totpLastStep = $step; return $this; }

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getTiknummer(): ?int { return $this->tiknummer; }
    public function setTiknummer(?int $tiknummer): static { $this->tiknummer = $tiknummer; return $this; }
    public function getZonenummer(): ?int { return $this->zonenummer; }
    public function setZonenummer(?int $zonenummer): static { $this->zonenummer = $zonenummer ?: null; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }
    public function getPassword(): string { return $this->code; }
    public function getRang(): ?Rang { return $this->rang; }
    public function setRang(?Rang $rang): static { $this->rang = $rang; return $this; }
    public function getPeloton(): ?int { return $this->peloton; }
    public function setPeloton(?int $peloton): static { $this->peloton = $peloton ?: null; return $this; }
    public function getPost(): ?Post { return $this->post; }
    public function setPost(?Post $post): static { $this->post = $post; return $this; }
    public function getNaam(): string { return $this->naam; }
    public function setNaam(string $naam): static { $this->naam = $naam; return $this; }
    public function getVoornaam(): string { return $this->voornaam; }
    public function setVoornaam(string $voornaam): static { $this->voornaam = $voornaam; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email ?: null; return $this; }
    public function isActief(): bool { return $this->actief; }
    public function setActief(bool $actief): static { $this->actief = $actief; return $this; }
    public function isBeroeps(): bool { return $this->beroeps; }
    public function setBeroeps(bool $beroeps): static { $this->beroeps = $beroeps; return $this; }
    public function getRol(): Rol { return $this->rol; }
    public function setRol(Rol $rol): static { $this->rol = $rol; return $this; }
    public function getSessieduur(): ?int { return $this->sessieduur; }
    public function setSessieduur(?int $sessieduur): static { $this->sessieduur = $sessieduur; return $this; }
    public function getVastPeloton(): ?int { return $this->vastPeloton; }
    public function setVastPeloton(?int $vastPeloton): static { $this->vastPeloton = $vastPeloton ?: null; return $this; }
    public function getLaatstGezien(): ?\DateTimeImmutable { return $this->laatstGezien; }
    public function setLaatstGezien(?\DateTimeImmutable $d): static { $this->laatstGezien = $d; return $this; }

    public function isSysteemaccount(): bool { return $this->systeemaccount; }
    public function setSysteemaccount(bool $v): static { $this->systeemaccount = $v; return $this; }

    public function isTikPc(): bool { return $this->rol === Rol::POST; }

    public function getVolledigeNaam(): string { return trim($this->naam.' '.$this->voornaam); }

    /** "Kpl. Peeters Jan" */
    public function getNaamMetRang(): string
    {
        return trim(($this->rang?->getKort() ?? '').' '.$this->getVolledigeNaam());
    }

    public function getRoles(): array
    {
        return [$this->rol->securityRole()];
    }

    /** Uniek over alle afdelingen: "<afdeling-slug>:<tiknummer>". */
    public function getUserIdentifier(): string
    {
        return $this->afdeling->getSlug().':'.$this->tiknummer;
    }

    public function __serialize(): array
    {
        // De sessie bevat enkel wat nodig is om de gebruiker opnieuw te laden.
        return [$this->id, $this->tiknummer, $this->code, $this->afdeling?->getSlug(), $this->rol->value];
    }

    public function __unserialize(array $data): void
    {
        [$this->id, $this->tiknummer, $this->code, $slug, $rol] = $data;
        $this->rol = Rol::from($rol);
        $this->afdeling = (new Afdeling())->setSlug((string) $slug);
    }

    public function __toString(): string { return $this->getVolledigeNaam(); }
}

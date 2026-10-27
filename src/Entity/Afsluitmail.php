<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Verzendregistratie van de mail bij het afsluiten van een lijst.
 * LijstService schrijft deze tabel rechtstreeks via DBAL; deze mapping houdt het
 * Doctrine-schema (schema:validate, migrations:diff, tests) in lijn met de migratie.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_afsluitmail_lijst', columns: ['lijst_id'])]
#[ORM\Index(name: 'IDX_afsluitmail_afdeling', columns: ['afdeling_id'])]
class Afsluitmail
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $lijstId = 0;

    #[ORM\Column]
    private int $afdelingId = 0;

    #[ORM\Column(length: 20)]
    private string $status = 'in_afwachting';

    #[ORM\Column(options: ['default' => 0])]
    private int $pogingen = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ontvangers = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fout = null;

    #[ORM\Column]
    private \DateTimeImmutable $aangemaaktOp;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verzondenOp = null;

    /** Tijdstip waarop de lopende verzending geclaimd werd; een oude claim mag opnieuw. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $bezigSinds = null;

    public function __construct() { $this->aangemaaktOp = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getLijstId(): int { return $this->lijstId; }
    public function getAfdelingId(): int { return $this->afdelingId; }
    public function getStatus(): string { return $this->status; }
    public function getPogingen(): int { return $this->pogingen; }
    public function getOntvangers(): ?string { return $this->ontvangers; }
    public function getFout(): ?string { return $this->fout; }
    public function getAangemaaktOp(): \DateTimeImmutable { return $this->aangemaaktOp; }
    public function getVerzondenOp(): ?\DateTimeImmutable { return $this->verzondenOp; }
    public function getBezigSinds(): ?\DateTimeImmutable { return $this->bezigSinds; }
}

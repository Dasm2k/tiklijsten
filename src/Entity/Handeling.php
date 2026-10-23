<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Append-only through the application. Contains no passwords, codes or 2FA secrets. */
#[ORM\Entity]
#[ORM\Index(name: 'idx_handeling_afdeling_datum', columns: ['afdeling_id', 'datum'])]
class Handeling
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column]
    private \DateTimeImmutable $datum;
    #[ORM\Column(nullable: true)]
    private ?int $afdelingId = null;
    #[ORM\Column(nullable: true)]
    private ?int $actorId = null;
    #[ORM\Column(length: 180)]
    private string $actor = '';
    #[ORM\Column(length: 64)]
    private string $actie = '';
    #[ORM\Column(length: 160)]
    private string $objectType = '';
    #[ORM\Column(nullable: true)]
    private ?int $objectId = null;
    #[ORM\Column(length: 160)]
    private string $route = '';
    #[ORM\Column(length: 64)]
    private string $requestId = '';
    #[ORM\Column(type: 'json')]
    private array $voor = [];
    #[ORM\Column(type: 'json')]
    private array $na = [];
    public function __construct(array $data)
    {
        $this->datum = new \DateTimeImmutable();
        foreach ($data as $key => $value) { $this->$key = $value; }
    }
    public function getId(): ?int { return $this->id; }
    public function getDatum(): \DateTimeImmutable { return $this->datum; }
    public function getAfdelingId(): ?int { return $this->afdelingId; }
    public function getActorId(): ?int { return $this->actorId; }
    public function getActor(): string { return $this->actor; }
    public function getActie(): string { return $this->actie; }
    public function getObjectType(): string { return $this->objectType; }
    public function getObjectId(): ?int { return $this->objectId; }
    public function getRoute(): string { return $this->route; }
    public function getRequestId(): string { return $this->requestId; }
    public function getVoor(): array { return $this->voor; }
    public function getNa(): array { return $this->na; }
}

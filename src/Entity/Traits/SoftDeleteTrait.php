<?php
namespace App\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;

trait SoftDeleteTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verwijderdOp = null;
    #[ORM\Column(nullable: true)]
    private ?int $verwijderdDoor = null;

    public function getVerwijderdOp(): ?\DateTimeImmutable { return $this->verwijderdOp; }
    public function getVerwijderdDoor(): ?int { return $this->verwijderdDoor; }
    public function isVerwijderd(): bool { return null !== $this->verwijderdOp; }
    public function markeerVerwijderd(?int $door = null): void { $this->verwijderdOp = new \DateTimeImmutable(); $this->verwijderdDoor = $door; }
    public function herstel(): void { $this->verwijderdOp = null; $this->verwijderdDoor = null; }
}

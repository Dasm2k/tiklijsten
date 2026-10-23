<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Per afdeling kan een fysieke post een afwijkende chauffeursinstelling hebben. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_chauffeur_instelling', columns: ['afdeling_id', 'post_id'])]
class ChauffeurInstelling
{
    use \App\Entity\Traits\SoftDeleteTrait;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Afdeling $afdeling = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Post $post = null;

    #[ORM\Column]
    private bool $actief = true;

    public function getId(): ?int { return $this->id; }
    public function getAfdeling(): ?Afdeling { return $this->afdeling; }
    public function setAfdeling(Afdeling $afdeling): static { $this->afdeling = $afdeling; return $this; }
    public function getPost(): ?Post { return $this->post; }
    public function setPost(Post $post): static { $this->post = $post; return $this; }
    public function isActief(): bool { return $this->actief; }
    public function setActief(bool $actief): static { $this->actief = $actief; return $this; }
}

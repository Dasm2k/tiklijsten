<?php
namespace App\Service;

use App\Entity\Aanwezigheidslijst;
use App\Entity\ChauffeurInstelling;
use Doctrine\ORM\EntityManagerInterface;

final class ChauffeurConfiguratie
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function isActief(Aanwezigheidslijst $lijst): bool
    {
        $afdeling = $lijst->getAfdeling();
        if (!$afdeling) { return false; }
        if ($lijst->getPost()) {
            $instelling = $this->em->getRepository(ChauffeurInstelling::class)->findOneBy(['afdeling' => $afdeling, 'post' => $lijst->getPost()]);
            if ($instelling) { return $instelling->isActief(); }
        }
        return $afdeling->isChauffeursActief();
    }

    public function isVerplicht(Aanwezigheidslijst $lijst): bool
    {
        return $this->isActief($lijst) && ($lijst->getInterventieType()?->isChauffeurVerplicht() ?? true);
    }
}

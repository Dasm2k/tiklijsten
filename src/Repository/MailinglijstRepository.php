<?php

namespace App\Repository;

use App\Entity\Afdeling;
use App\Entity\Mailinglijst;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Mailinglijst> */
class MailinglijstRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailinglijst::class);
    }

    /** @return Mailinglijst[] */
    public function voorAfdeling(Afdeling $afdeling): array
    {
        return $this->findBy(['afdeling' => $afdeling], ['naam' => 'ASC']);
    }

    /** @return string[] ontvangers wanneer een lijst van dit peloton wordt afgesloten */
    public function ontvangersAfgeslotenLijst(Afdeling $afdeling, int $peloton): array
    {
        $lijsten = $this->createQueryBuilder('m')->addSelect('l')->leftJoin('m.leden', 'l')
            ->andWhere('m.afdeling = :a')->setParameter('a', $afdeling)
            ->andWhere('m.peloton = :p OR m.alleLijsten = true')->setParameter('p', $peloton)
            ->getQuery()->getResult();
        return $this->adressen($lijsten);
    }

    /** @return string[] */
    public function ontvangersFoutmeldingen(Afdeling $afdeling): array
    {
        $lijsten = $this->createQueryBuilder('m')->addSelect('l')->leftJoin('m.leden', 'l')
            ->andWhere('m.afdeling = :a')->setParameter('a', $afdeling)
            ->andWhere('m.foutmeldingen = true')
            ->getQuery()->getResult();
        return $this->adressen($lijsten);
    }

    /** @param Mailinglijst[] $lijsten */
    private function adressen(array $lijsten): array
    {
        $adressen = [];
        foreach ($lijsten as $lijst) {
            array_push($adressen, ...$lijst->getEmailadressen());
        }
        return array_values(array_unique($adressen));
    }
}

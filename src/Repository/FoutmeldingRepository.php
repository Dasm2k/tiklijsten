<?php

namespace App\Repository;

use App\Entity\Afdeling;
use App\Entity\Foutmelding;
use App\Entity\Persoon;
use App\Enum\FoutStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Foutmelding> */
class FoutmeldingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Foutmelding::class);
    }

    /** @return Foutmelding[] */
    public function overzicht(Afdeling $afdeling, ?Persoon $enkelVoorOpvolger = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->addSelect('m', 'o')
            ->leftJoin('f.melder', 'm')
            ->leftJoin('f.opvolger', 'o')
            ->andWhere('f.afdeling = :a')->setParameter('a', $afdeling)
            ->orderBy('f.id', 'DESC');
        if ($enkelVoorOpvolger) {
            $qb->andWhere('f.opvolger = :p')->setParameter('p', $enkelVoorOpvolger);
        }
        return $qb->getQuery()->getResult();
    }

    public function findInAfdeling(Afdeling $afdeling, int $id): ?Foutmelding
    {
        return $this->findOneBy(['afdeling' => $afdeling, 'id' => $id]);
    }

    /**
     * Zoals in de oude toepassing: afgewerkte en definitief buitendienst gestelde meldingen
     * verdwijnen een dag na het afsluiten.
     */
    public function ruimOp(\DateTimeImmutable $voor): int
    {
        $ids = $this->createQueryBuilder('f')->select('f.id')
            ->andWhere('f.status IN (:st)')->setParameter('st', [FoutStatus::DEFINITIEF_BUITENDIENST, FoutStatus::AFGEWERKT])
            ->andWhere('f.afgesloten IS NOT NULL AND f.afgesloten < :voor')->setParameter('voor', $voor)
            ->getQuery()->getSingleColumnResult();
        if (!$ids) {
            return 0;
        }
        $em = $this->getEntityManager();
        $em->createQuery('DELETE FROM App\Entity\Foutbericht b WHERE b.foutmelding IN (:ids)')->setParameter('ids', $ids)->execute();
        return $em->createQuery('DELETE FROM App\Entity\Foutmelding f WHERE f.id IN (:ids)')->setParameter('ids', $ids)->execute();
    }
}

<?php

namespace App\Repository;

use App\Entity\Afdeling;
use App\Entity\InterventieType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InterventieType> */
class InterventieTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterventieType::class);
    }

    public function keuzeQuery(Afdeling $afdeling, bool $enkelActief = true): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.afdeling = :a')->setParameter('a', $afdeling)
            ->orderBy('t.volgorde', 'ASC')->addOrderBy('t.naam', 'ASC');
        if ($enkelActief) {
            $qb->andWhere('t.actief = true');
        }
        return $qb;
    }
}

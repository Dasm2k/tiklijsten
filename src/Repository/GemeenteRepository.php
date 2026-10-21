<?php

namespace App\Repository;

use App\Entity\Afdeling;
use App\Entity\Gemeente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Gemeente> */
class GemeenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Gemeente::class);
    }

    public function keuzeQuery(Afdeling $afdeling, bool $enkelActief = true): QueryBuilder
    {
        $qb = $this->createQueryBuilder('g')
            ->andWhere('g.afdeling = :a')->setParameter('a', $afdeling)
            ->orderBy('g.naam', 'ASC');
        if ($enkelActief) {
            $qb->andWhere('g.actief = true');
        }
        return $qb;
    }
}

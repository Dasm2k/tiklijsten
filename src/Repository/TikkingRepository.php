<?php

namespace App\Repository;

use App\Entity\Persoon;
use App\Entity\Tikking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tikking> */
class TikkingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tikking::class);
    }

    private function vanPersoon(Persoon $persoon, ?\DateTimeImmutable $van, ?\DateTimeImmutable $tot): QueryBuilder
    {
        $qb = $this->createQueryBuilder('tk')
            ->addSelect('l', 'g', 't')
            ->join('tk.lijst', 'l')
            ->leftJoin('l.gemeente', 'g')
            ->leftJoin('l.interventieType', 't')
            ->andWhere('tk.persoon = :p')->setParameter('p', $persoon)
            ->orderBy('l.start', 'DESC');
        if ($van) {
            $qb->andWhere('l.start >= :van')->setParameter('van', $van->setTime(0, 0));
        }
        if ($tot) {
            $qb->andWhere('l.start < :tot')->setParameter('tot', $tot->setTime(0, 0)->modify('+1 day'));
        }
        return $qb;
    }

    /** @return Paginator<Tikking> */
    public function pagina(Persoon $persoon, ?\DateTimeImmutable $van, ?\DateTimeImmutable $tot, int $pagina, int $perPagina = 25): Paginator
    {
        $q = $this->vanPersoon($persoon, $van, $tot)
            ->setFirstResult(max(0, $pagina - 1) * $perPagina)->setMaxResults($perPagina)->getQuery();
        return new Paginator($q, fetchJoinCollection: false);
    }

    /** @return Tikking[] */
    public function alle(Persoon $persoon, ?\DateTimeImmutable $van, ?\DateTimeImmutable $tot): array
    {
        return $this->vanPersoon($persoon, $van, $tot)->getQuery()->getResult();
    }

    /** Totale prestatie in seconden binnen de periode. */
    public function totaalSeconden(Persoon $persoon, ?\DateTimeImmutable $van, ?\DateTimeImmutable $tot): int
    {
        $totaal = 0;
        foreach ($this->vanPersoon($persoon, $van, $tot)->getQuery()->toIterable() as $tikking) {
            $totaal += $tikking->getPrestatie();
        }
        return $totaal;
    }

    /**
     * @param int[] $lijstIds
     * @return int[] ids van lijsten waarvoor de persoon getikt heeft
     */
    public function lijstenMetTikking(Persoon $persoon, array $lijstIds): array
    {
        if (!$lijstIds) {
            return [];
        }
        $rijen = $this->createQueryBuilder('tk')->select('IDENTITY(tk.lijst) AS lijst')
            ->andWhere('tk.persoon = :p')->setParameter('p', $persoon)
            ->andWhere('tk.lijst IN (:ids)')->setParameter('ids', $lijstIds)
            ->getQuery()->getScalarResult();
        return array_map('intval', array_column($rijen, 'lijst'));
    }
}

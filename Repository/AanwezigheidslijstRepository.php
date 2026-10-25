<?php

namespace App\Repository;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Afdeling;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Aanwezigheidslijst> */
class AanwezigheidslijstRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Aanwezigheidslijst::class);
    }

    private function basis(Afdeling $afdeling): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->addSelect('g', 't', 'b', 'po')
            ->leftJoin('l.gemeente', 'g')
            ->leftJoin('l.interventieType', 't')
            ->leftJoin('l.bevelvoerder', 'b')
            ->leftJoin('l.post', 'po')
            ->andWhere('l.afdeling = :a')->setParameter('a', $afdeling);
    }

    public function findInAfdeling(Afdeling $afdeling, int $id): ?Aanwezigheidslijst
    {
        return $this->basis($afdeling)->andWhere('l.id = :id')->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return Aanwezigheidslijst[] */
    public function laatste(Afdeling $afdeling, int $aantal = 10): array
    {
        return $this->basis($afdeling)->orderBy('l.start', 'DESC')->addOrderBy('l.id', 'DESC')
            ->setMaxResults($aantal)->getQuery()->getResult();
    }

    /** @return Aanwezigheidslijst[] */
    public function open(Afdeling $afdeling, ?Post $post = null): array
    {
        $qb = $this->basis($afdeling)->andWhere('l.afgesloten = false')->orderBy('l.id', 'DESC');
        if ($post) {
            $qb->andWhere('l.post = :post')->setParameter('post', $post);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return Paginator<Aanwezigheidslijst> */
    public function pagina(Afdeling $afdeling, bool $archief, int $pagina, int $perPagina = 25, ?string $zoek = null): Paginator
    {
        $qb = $this->basis($afdeling)
            ->andWhere('l.gearchiveerd = :arch')->setParameter('arch', $archief)
            ->orderBy('l.start', 'DESC')->addOrderBy('l.id', 'DESC')
            ->setFirstResult(max(0, $pagina - 1) * $perPagina)
            ->setMaxResults($perPagina);
        if (null !== $zoek && '' !== $zoek) {
            if (ctype_digit(ltrim($zoek, '#'))) {
                $qb->andWhere(str_starts_with($zoek, '#') ? 'COALESCE(l.volgnummer, l.nummer) = :nr' : '(COALESCE(l.volgnummer, l.nummer) = :nr OR l.interventienummer = :nr)')
                    ->setParameter('nr', (int) ltrim($zoek, '#'));
            } else {
                $qb->andWhere('g.naam LIKE :z OR t.naam LIKE :z OR l.info LIKE :z')->setParameter('z', '%'.$zoek.'%');
            }
        }
        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    public function volgendNummer(Afdeling $afdeling): int
    {
        // Include deleted lists so a historical list number is never recycled.
        return 1 + (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT MAX(nummer) FROM aanwezigheidslijst WHERE afdeling_id = ?',[$afdeling->getId()]);
    }

    public function volgendVolgnummer(Afdeling $afdeling, int $peloton): int
    {
        return 1 + (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT MAX(volgnummer) FROM aanwezigheidslijst WHERE afdeling_id = ? AND peloton = ?',[$afdeling->getId(),$peloton]);
    }

    /** @return array<int, array{label: string, aantal: int}> */
    public function telPer(Afdeling $afdeling, string $veld): array
    {
        $qb = $this->createQueryBuilder('l')->andWhere('l.afdeling = :a')->setParameter('a', $afdeling);
        switch ($veld) {
            case 'peloton':
                $qb->select('l.peloton AS label', 'COUNT(l.id) AS aantal')->groupBy('l.peloton')->orderBy('l.peloton');
                break;
            case 'interventie':
                $qb->select('t.naam AS label', 'COUNT(l.id) AS aantal')->leftJoin('l.interventieType', 't')
                    ->groupBy('t.id')->orderBy('aantal', 'DESC');
                break;
            case 'gemeente':
                $qb->select('g.naam AS label', 'COUNT(l.id) AS aantal')->leftJoin('l.gemeente', 'g')
                    ->groupBy('g.id')->orderBy('aantal', 'DESC');
                break;
            default:
                throw new \InvalidArgumentException($veld);
        }
        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Aantal interventies per jaar en per peloton (enkel types die meetellen).
     *
     * @return array<int, array<int, int>> [jaar => [peloton => aantal]]
     */
    public function perJaarEnPeloton(Afdeling $afdeling): array
    {
        $rijen = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT YEAR(l.start) AS jaar, l.peloton, COUNT(*) AS aantal
               FROM aanwezigheidslijst l
               LEFT JOIN interventie_type t ON t.id = l.interventie_type_id
              WHERE l.verwijderd_op IS NULL AND l.afdeling_id = :a AND (t.id IS NULL OR t.in_statistiek = 1)
              GROUP BY YEAR(l.start), l.peloton
              ORDER BY jaar DESC, l.peloton',
            ['a' => $afdeling->getId()]
        );
        $resultaat = [];
        foreach ($rijen as $r) {
            $resultaat[(int) $r['jaar']][(int) $r['peloton']] = (int) $r['aantal'];
        }
        return $resultaat;
    }
}

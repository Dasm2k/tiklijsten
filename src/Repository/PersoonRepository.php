<?php

namespace App\Repository;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<Persoon> */
class PersoonRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public const SORTEERVELDEN = [
        'tiknummer' => 'p.tiknummer',
        'zonenummer' => 'p.zonenummer',
        'rang' => 'r.niveau',
        'peloton' => 'p.peloton',
        'post' => 'po.naam',
        'naam' => 'p.naam',
        'voornaam' => 'p.voornaam',
        'beroeps' => 'p.beroeps',
        'email' => 'p.email',
        'rol' => 'p.rol',
        'gezien' => 'p.laatstGezien',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Persoon::class);
    }

    public function findOneByIdentifier(string $afdelingSlug, int $tiknummer): ?Persoon
    {
        return $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.afdeling', 'a')
            ->andWhere('a.slug = :slug')->setParameter('slug', $afdelingSlug)
            ->andWhere('p.tiknummer = :nr')->setParameter('nr', $tiknummer)
            ->getQuery()->getOneOrNullResult();
    }

    public function findOneInAfdeling(Afdeling $afdeling, int $tiknummer): ?Persoon
    {
        return $this->findOneBy(['afdeling' => $afdeling, 'tiknummer' => $tiknummer]);
    }

    /** Personeel (zonder systeemaccounts). */
    public function personeelQuery(Afdeling $afdeling, ?bool $actief = true): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('r', 'po')
            ->leftJoin('p.rang', 'r')
            ->leftJoin('p.post', 'po')
            ->andWhere('p.afdeling = :a')->setParameter('a', $afdeling)
            ->andWhere('p.systeemaccount = false');
        if (null !== $actief) {
            $qb->andWhere('p.actief = :actief')->setParameter('actief', $actief);
        }
        return $qb;
    }

    /** @return Persoon[] */
    public function personeel(Afdeling $afdeling, ?bool $actief = true, string $sort = 'naam', string $richting = 'ASC', string $zoek = ''): array
    {
        $veld = self::SORTEERVELDEN[$sort] ?? 'p.naam';
        $richting = 'DESC' === strtoupper($richting) ? 'DESC' : 'ASC';
        $qb = $this->personeelQuery($afdeling, $actief)
            ->addSelect('cp')->leftJoin('p.chauffeurPosten', 'cp');
        // Elk zoekwoord moet in minstens één veld voorkomen; de volgorde van voor- en achternaam is vrij.
        foreach (preg_split('/\s+/u', mb_strtolower(trim(mb_substr($zoek, 0, 200))), -1, PREG_SPLIT_NO_EMPTY) as $index => $woord) {
            $parameter = 'zoek'.$index;
            $velden = ['LOWER(p.naam)', 'LOWER(p.voornaam)', 'p.tiknummer', 'p.zonenummer', 'LOWER(p.email)', 'LOWER(po.naam)', 'LOWER(po.code)', 'LOWER(r.naam)', 'LOWER(r.kort)'];
            $qb->andWhere($qb->expr()->orX(...array_map(static fn (string $veld): string => $veld.' LIKE :'.$parameter." ESCAPE '!'", $velden)))
                ->setParameter($parameter, '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $woord).'%');
        }
        return $qb
            ->orderBy($veld, $richting)->addOrderBy('p.naam', 'ASC')->addOrderBy('p.voornaam', 'ASC')
            ->getQuery()->getResult();
    }

    /** Keuzelijst voor bevelvoerder, opvolger, ... */
    public function keuzeQuery(Afdeling $afdeling): QueryBuilder
    {
        return $this->personeelQuery($afdeling, true)->orderBy('p.naam', 'ASC')->addOrderBy('p.voornaam', 'ASC');
    }

    /** @return Persoon[] actieve leden van een peloton, hoogste rang eerst */
    public function vanPeloton(Afdeling $afdeling, int $peloton): array
    {
        return $this->personeelQuery($afdeling, true)
            ->andWhere('p.peloton = :pel')->setParameter('pel', $peloton)
            ->orderBy('r.niveau', 'DESC')->addOrderBy('p.naam', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return array<int, array{rang: string, aantal: int}> */
    public function telPerRang(Afdeling $afdeling): array
    {
        return $this->personeelQuery($afdeling, true)
            ->select('r.naam AS rang', 'COUNT(p.id) AS aantal')
            ->groupBy('r.id')
            ->orderBy('r.niveau', 'DESC')
            ->getQuery()->getArrayResult();
    }

    /** @return string[] e-mailadressen van alle actieve personeelsleden */
    public function alleEmailadressen(Afdeling $afdeling, int $minimumRangNiveau = 0): array
    {
        $qb = $this->personeelQuery($afdeling, true)
            ->select('p.email')
            ->andWhere('p.email IS NOT NULL');
        if ($minimumRangNiveau > 0) {
            $qb->andWhere('r.niveau >= :niveau')->setParameter('niveau', $minimumRangNiveau);
        }
        return array_values(array_unique(array_column($qb->getQuery()->getArrayResult(), 'email')));
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Persoon) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setCode($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}

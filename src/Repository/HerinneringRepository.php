<?php

namespace App\Repository;

use App\Entity\Herinnering;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Herinnering> */
class HerinneringRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Herinnering::class);
    }

    /** @return Herinnering[] */
    public function voorAfdeling(\App\Entity\Afdeling $afdeling): array
    {
        return $this->findBy(["afdeling" => $afdeling], ["id" => "ASC"]);
    }
}

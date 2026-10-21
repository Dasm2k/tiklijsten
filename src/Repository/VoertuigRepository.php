<?php

namespace App\Repository;

use App\Entity\Voertuig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Voertuig> */
class VoertuigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voertuig::class);
    }

    /** @return Voertuig[] */
    public function voorAfdeling(\App\Entity\Afdeling $afdeling): array
    {
        return $this->findBy(["afdeling" => $afdeling], ["naam" => "ASC"]);
    }
}

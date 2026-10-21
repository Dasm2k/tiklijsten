<?php

namespace App\Repository;

use App\Entity\Materiaal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Materiaal> */
class MateriaalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Materiaal::class);
    }

    /** @return Materiaal[] */
    public function voorAfdeling(\App\Entity\Afdeling $afdeling): array
    {
        return $this->findBy(["afdeling" => $afdeling], ["naam" => "ASC"]);
    }
}

<?php

namespace App\Repository;

use App\Entity\Afdeling;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Afdeling> */
class AfdelingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Afdeling::class);
    }

    /** @return Afdeling[] */
    public function findAllGesorteerd(): array
    {
        return $this->findBy([], ["volgorde" => "ASC", "naam" => "ASC"]);
    }
}

<?php

namespace App\Repository;

use App\Entity\Rang;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Rang> */
class RangRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rang::class);
    }

    /** @return Rang[] */
    public function findAllGesorteerd(): array
    {
        return $this->findBy([], ["niveau" => "DESC"]);
    }
}

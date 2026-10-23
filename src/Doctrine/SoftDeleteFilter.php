<?php
namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return $targetEntity->hasField('verwijderdOp') ? $targetTableAlias.'.'.$targetEntity->getColumnName('verwijderdOp').' IS NULL' : '';
    }
}

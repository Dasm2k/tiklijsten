<?php
namespace App\Doctrine;

use App\Service\AuditContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;

#[AsDoctrineListener(event: 'onFlush', priority: 100)]
final class SoftDeleteListener
{
    public function __construct(private readonly AuditContext $context) {}
    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager(); $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!method_exists($entity, 'markeerVerwijderd')) { continue; }
            // Persist cancels a scheduled removal; the original data and references remain.
            $em->persist($entity);
            $entity->markeerVerwijderd($this->context->actor()?->getId());
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
        }
    }
}

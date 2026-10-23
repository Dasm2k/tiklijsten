<?php
namespace App\Doctrine;

use App\Entity\Handeling;
use App\Service\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;

#[AsDoctrineListener(event: 'postPersist')]
#[AsDoctrineListener(event: 'postUpdate')]
#[AsDoctrineListener(event: 'onFlush', priority: -100)]
final class AuditListener
{
    private const GEHEIM = ['code','totpSecret','totpLastStep'];
    public function __construct(private readonly AuditLog $log) {}
    public function postPersist(LifecycleEventArgs $event): void { $this->record($event, true); }
    public function postUpdate(LifecycleEventArgs $event): void { $this->record($event, false); }
    private function record(LifecycleEventArgs $event, bool $nieuw): void
    {
        $entity = $event->getObject(); if ($entity instanceof Handeling) { return; }
        $em = $event->getObjectManager(); $meta = $em->getClassMetadata($entity::class);
        $changes = $em->getUnitOfWork()->getEntityChangeSet($entity); $voor = []; $na = [];
        foreach ($changes as $veld => [$oud, $nieuwWaarde]) {
            if ('totpLastStep' === $veld) { continue; }
            $voor[$veld] = in_array($veld, self::GEHEIM, true) ? '[afgeschermd]' : $this->log->waarde($oud);
            $na[$veld] = in_array($veld, self::GEHEIM, true) ? '[afgeschermd]' : $this->log->waarde($nieuwWaarde);
        }
        if (!$na && !$nieuw) { return; }
        $actie = $nieuw ? 'aanmaken' : 'wijzigen';
        if (!$nieuw && isset($changes['verwijderdOp'])) { $actie = $changes['verwijderdOp'][1] ? 'verwijderen' : 'herstellen'; }
        elseif (!$nieuw && isset($changes['afgesloten'])) { $actie = $changes['afgesloten'][1] ? 'afsluiten' : 'heropenen'; }
        $this->log->schrijf($em->getConnection(), $this->log->data($actie, $meta->getName(), $entity->getId(), $voor, $na, $this->log->afdeling($entity)));
    }
    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager(); $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $owner = $collection->getOwner();
            if ($owner instanceof Handeling || !method_exists($owner, 'getId')) { continue; }
            $data = $this->log->data('relatie wijzigen', $em->getClassMetadata($owner::class)->getName(), $owner->getId(),
                ['leden' => array_map($this->log->waarde(...), $collection->getSnapshot())],
                ['leden' => array_map($this->log->waarde(...), $collection->toArray())], $this->log->afdeling($owner));
            $record = new Handeling($data); $em->persist($record); $uow->computeChangeSet($em->getClassMetadata(Handeling::class), $record);
        }
    }
}

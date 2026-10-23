<?php
namespace App\Service;

use App\Entity\Handeling;
use Doctrine\DBAL\Connection;

final class AuditLog
{
    public function __construct(private readonly AuditContext $context) {}
    public function data(string $actie, string $type, ?int $id, array $voor, array $na, ?int $afdeling = null): array
    {
        $data = $this->context->data();
        if ($afdeling !== null) { $data['afdelingId'] = $afdeling; }
        return $data + ['actie' => $actie, 'objectType' => $type, 'objectId' => $id, 'voor' => $voor, 'na' => $na];
    }
    public function schrijf(Connection $db, array $data): void
    {
        // Called from Doctrine postPersist/postUpdate inside the original write transaction.
        $db->insert('handeling', [
            'datum' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'afdeling_id' => $data['afdelingId'], 'actor_id' => $data['actorId'], 'actor' => $data['actor'],
            'actie' => $data['actie'], 'object_type' => $data['objectType'], 'object_id' => $data['objectId'],
            'route' => $data['route'], 'request_id' => $data['requestId'],
            'voor' => json_encode($data['voor'], JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),
            'na' => json_encode($data['na'], JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
    public function waarde(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) { return $value->format(DATE_ATOM); }
        if ($value instanceof \BackedEnum) { return $value->value; }
        if (is_object($value)) { return method_exists($value, 'getId') ? ['type' => basename(str_replace('\\','/',$value::class)), 'id' => $value->getId()] : get_debug_type($value); }
        return $value;
    }
    public function afdeling(object $entity): ?int
    {
        if ($entity instanceof \App\Entity\Afdeling) { return $entity->getId(); }
        if (method_exists($entity, 'getAfdeling')) { return $entity->getAfdeling()?->getId(); }
        if (method_exists($entity, 'getLijst')) { return $entity->getLijst()?->getAfdeling()?->getId(); }
        if (method_exists($entity, 'getFoutmelding')) { return $entity->getFoutmelding()?->getAfdeling()?->getId(); }
        return null;
    }
}

<?php
namespace App\Service;

use App\Entity\Persoon;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class AuditContext
{
    public function __construct(private readonly TokenStorageInterface $tokens, private readonly RequestStack $requests) {}
    public function namens(Persoon $persoon): void { $this->requests->getCurrentRequest()?->attributes->set('_audit_actor', $persoon); }
    public function actor(): ?Persoon
    {
        $persoon = $this->requests->getCurrentRequest()?->attributes->get('_audit_actor') ?? $this->tokens->getToken()?->getUser();
        return $persoon instanceof Persoon ? $persoon : null;
    }
    public function data(): array
    {
        $request = $this->requests->getCurrentRequest(); $actor = $this->actor();
        if ($request && !$request->attributes->has('_audit_request_id')) { $request->attributes->set('_audit_request_id', bin2hex(random_bytes(12))); }
        return [
            'actorId' => $actor?->getId(), 'actor' => $actor?->getVolledigeNaam() ?? 'Systeem / console / niet aangemeld',
            'afdelingId' => $actor?->getAfdeling()?->getId(),
            'route' => (string) $request?->attributes->get('_route', 'console'),
            'requestId' => (string) $request?->attributes->get('_audit_request_id', ''),
        ];
    }
}

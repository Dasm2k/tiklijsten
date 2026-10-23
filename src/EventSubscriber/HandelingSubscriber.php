<?php
namespace App\EventSubscriber;

use App\Service\AuditLog;
use App\Service\AfdelingContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Logs completed application requests, including login failures and read/export actions. */
final class HandelingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Connection $db, private readonly AuditLog $log, private readonly AfdelingContext $context) {}
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => ['response', -100]]; }
    public function response(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest(); $route = (string) $request->attributes->get('_route');
        if (!str_starts_with($route, 'app_')) { return; }
        $ids = [];
        foreach (['id', 'tikking', 'soort'] as $key) { if ($request->attributes->has($key)) { $ids[$key] = $request->attributes->get($key); } }
        $this->log->schrijf($this->db, $this->log->data('verzoek', 'HTTP', null, [], ['methode'=>$request->getMethod(), 'status'=>$event->getResponse()->getStatusCode(), 'objecten'=>$ids], $this->context->huidige()?->getId()));
    }
}

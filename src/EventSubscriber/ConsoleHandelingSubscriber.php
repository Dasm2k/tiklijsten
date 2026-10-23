<?php
namespace App\EventSubscriber;

use App\Service\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConsoleHandelingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Connection $db,private readonly AuditLog $log) {}
    public static function getSubscribedEvents(): array { return [ConsoleEvents::TERMINATE=>'terminate']; }
    public function terminate(ConsoleTerminateEvent $event): void
    {
        // No command arguments: they may contain credentials or a database URL.
        if (!$this->db->createSchemaManager()->tablesExist(['handeling'])) { return; }
        $this->log->schrijf($this->db,$this->log->data('console','Console',null,[],['opdracht'=>$event->getCommand()?->getName(),'exitcode'=>$event->getExitCode()]));
    }
}

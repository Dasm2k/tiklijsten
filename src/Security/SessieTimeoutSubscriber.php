<?php

namespace App\Security;

use App\Entity\Persoon;
use App\Service\PostFoutmeldingToegang;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Automatisch afmelden na inactiviteit. De duur komt van de persoon (indien ingesteld)
 * of anders van de afdeling; 0 betekent nooit (bv. voor de tik-pc op de post).
 */
final class SessieTimeoutSubscriber implements EventSubscriberInterface
{
    public const LAATSTE_ACTIVITEIT = 'laatste_activiteit';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly PostFoutmeldingToegang $meldToegang,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Na de firewall (prioriteit 8), zodat de gebruiker gekend is.
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $persoon = $this->security->getUser();
        if (!$persoon instanceof Persoon) {
            return;
        }
        $session = $event->getRequest()->getSession();
        $route = $event->getRequest()->attributes->get('_route');
        if ($persoon->isTikPc() && is_string($route) && str_starts_with($route, 'app_') && !str_starts_with($route, 'app_fout_post_')) {
            $this->meldToegang->afmelden($session);
        }
        $session->set('laatste_afdeling', $persoon->getAfdeling()->getSlug());

        $minuten = $persoon->getSessieduur() ?? $persoon->getAfdeling()->getSessieduur();
        $laatste = (int) $session->get(self::LAATSTE_ACTIVITEIT, time());

        if ($minuten > 0 && time() - $laatste > $minuten * 60) {
            $slug = $persoon->getAfdeling()->getSlug();
            $this->security->logout(false);
            $event->getRequest()->getSession()->getFlashBag()->add('warning', 'Je bent automatisch afgemeld wegens inactiviteit.');
            $event->setResponse(new RedirectResponse($this->urls->generate('app_login', ['afdeling' => $slug])));
            return;
        }
        $session->set(self::LAATSTE_ACTIVITEIT, time());
    }
}

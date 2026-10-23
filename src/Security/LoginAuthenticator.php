<?php

namespace App\Security;

use App\Repository\AfdelingRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** Aanmelden met tiknummer + code, binnen een gekozen afdeling. */
final class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly AfdelingRepository $afdelingen,
        private readonly PersoonProvider $provider,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && self::LOGIN_ROUTE === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $slug = (string) $request->attributes->get('afdeling');
        $tiknummer = trim((string) $request->request->get('tiknummer'));
        $code = (string) $request->request->get('code');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $tiknummer);

        if (!$this->afdelingen->findOneBy(['slug' => $slug])) {
            throw new CustomUserMessageAuthenticationException('Onbekende afdeling.');
        }
        if (!ctype_digit($tiknummer)) {
            throw new CustomUserMessageAuthenticationException('Geef je tiknummer in (enkel cijfers).');
        }

        return new Passport(
            new UserBadge($slug.':'.$tiknummer, function (string $identifier) {
                $persoon = $this->provider->loadUserByIdentifier($identifier);
                if ($persoon instanceof \App\Entity\Persoon && $persoon->isSuperadmin()) {
                    throw new CustomUserMessageAuthenticationException('Gebruik de superadmin-aanmelding met 2FA.');
                }
                return $persoon;
            }),
            new PasswordCredentials($code),
            [
                new CsrfTokenBadge('login', (string) $request->request->get('_csrf_token')),
                new PasswordUpgradeBadge($code),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->set(SessieTimeoutSubscriber::LAATSTE_ACTIVITEIT, time());
        if ($target = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($target);
        }
        return new RedirectResponse($this->urls->generate('app_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        // Geen afdeling bekend: terug naar het portaal om er een te kiezen.
        $slug = $request->attributes->get('afdeling') ?? $request->getSession()->get('laatste_afdeling');
        return $slug
            ? $this->urls->generate(self::LOGIN_ROUTE, ['afdeling' => $slug])
            : $this->urls->generate('app_portaal');
    }
}

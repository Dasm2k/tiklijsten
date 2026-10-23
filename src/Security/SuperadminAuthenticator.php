<?php
namespace App\Security;

use App\Entity\Persoon;
use App\Enum\Rol;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class SuperadminAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly PersoonRepository $personen,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Totp $totp,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && 'app_superadmin_login' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $email = strtolower(trim((string) $request->request->get('email')));
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        $user = $this->personen->findOneBy(['email' => $email, 'rol' => Rol::SUPERADMIN, 'systeemaccount' => true]);
        // Unknown email still performs a bcrypt check to reduce account enumeration by timing.
        if (!$user) {
            password_verify((string) $request->request->get('password'), '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
            throw new CustomUserMessageAuthenticationException('Ongeldige aanmeldgegevens of authenticatorcode.');
        }
        return new Passport(
            new UserBadge($user->getUserIdentifier(), fn () => $user),
            new CustomCredentials(function (array $credentials, Persoon $persoon): bool {
                if (!$persoon->isSuperadmin() || !$persoon->isSysteemaccount() || !$persoon->isActief()
                    || !$this->hasher->isPasswordValid($persoon, $credentials[0]) || !$persoon->getTotpSecret()) { return false; }
                $step = $this->totp->matchingStep($persoon->getTotpSecret(), $credentials[1]);
                if (null === $step) { return false; }
                // Atomic consumption prevents concurrent reuse of an authenticator code.
                return 1 === $this->em->getConnection()->executeStatement(
                    'UPDATE persoon SET totp_last_step = ? WHERE id = ? AND actief = 1 AND rol = ? AND code = ? AND totp_secret = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
                    [$step, $persoon->getId(), Rol::SUPERADMIN->value, $persoon->getCode(), $persoon->getTotpSecret(), $step],
                );
            }, [(string) $request->request->get('password'), trim((string) $request->request->get('totp'))]),
            [new CsrfTokenBadge('superadmin_login', (string) $request->request->get('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->remove('superadmin_afdeling');
        $request->getSession()->set(SessieTimeoutSubscriber::LAATSTE_ACTIVITEIT, time());
        return new RedirectResponse($this->urls->generate('app_superadmin_afdelingen'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_superadmin_login');
    }
}

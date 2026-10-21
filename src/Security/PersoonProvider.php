<?php

namespace App\Security;

use App\Entity\Persoon;
use App\Repository\PersoonRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Laadt een persoon op basis van "<afdeling-slug>:<tiknummer>".
 *
 * @implements UserProviderInterface<Persoon>
 */
final class PersoonProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly PersoonRepository $personen)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        [$slug, $nummer] = array_pad(explode(':', $identifier, 2), 2, '');
        if ('' === $slug || !ctype_digit($nummer)) {
            throw new UserNotFoundException();
        }
        $persoon = $this->personen->findOneByIdentifier($slug, (int) $nummer);
        if (!$persoon) {
            $e = new UserNotFoundException();
            $e->setUserIdentifier($identifier);
            throw $e;
        }
        return $persoon;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Persoon) {
            throw new UnsupportedUserException();
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return Persoon::class === $class || is_subclass_of($class, Persoon::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $this->personen->upgradePassword($user, $newHashedPassword);
    }
}

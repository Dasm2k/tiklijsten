<?php

namespace App\Security;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Persoon;
use App\Service\AfdelingContext;
use App\Service\SpecialeLijstRechten;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class LijstAfdrukVoter extends Voter
{
    public const AFDRUKKEN = 'LIJST_AFDRUKKEN';

    public function __construct(
        private readonly RoleHierarchyInterface $rollen,
        private readonly AfdelingContext $afdelingContext,
        private readonly SpecialeLijstRechten $specialeRechten,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::AFDRUKKEN === $attribute && $subject instanceof Aanwezigheidslijst;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $persoon = $token->getUser();
        if (!$persoon instanceof Persoon || !$persoon->isActief() || $persoon->isVerwijderd()) {
            return false;
        }
        if (!$subject->isAfgesloten() || $subject->getAfdeling()?->getId() !== $this->afdelingContext->huidige()?->getId()) {
            return false;
        }

        $rollen = $this->rollen->getReachableRoleNames($persoon->getRoles());
        if (in_array('ROLE_PERSONEEL', $rollen, true)) {
            return true;
        }

        $eigenPost = !$persoon->getPost() || $persoon->getPost()->getId() === $subject->getPost()?->getId();
        if (in_array('ROLE_POST', $rollen, true)) {
            return $eigenPost;
        }

        return $subject->isSpeciaal() && $eigenPost && $this->specialeRechten->magAfsluiten($subject, $persoon);
    }
}

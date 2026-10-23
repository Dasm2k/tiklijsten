<?php
namespace App\Service;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Persoon;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class SpecialeLijstRechten
{
    public function __construct(private readonly RoleHierarchyInterface $rollen) {}
    public function isBeheerder(Persoon $persoon): bool
    {
        return in_array('ROLE_BEHEER', $this->rollen->getReachableRoleNames($persoon->getRoles()), true);
    }
    public function magAfsluiten(Aanwezigheidslijst $lijst, ?Persoon $persoon): bool
    {
        if (!$persoon || !$persoon->isActief() || $persoon->isVerwijderd()) { return false; }
        if (!$persoon->isSuperadmin() && $persoon->getAfdeling()?->getId() !== $lijst->getAfdeling()?->getId()) { return false; }
        if ($this->isBeheerder($persoon)) { return true; }
        if ($persoon->isSysteemaccount()) { return false; }
        $rang = mb_strtolower(($persoon->getRang()?->getNaam() ?? '').' '.($persoon->getRang()?->getKort() ?? ''));
        return (bool) preg_match('/sergeant|adjudant|\bsgt\b|\bs\.gt\b|\badj\b/u', $rang);
    }
}

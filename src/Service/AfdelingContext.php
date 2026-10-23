<?php
namespace App\Service;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use App\Repository\AfdelingRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class AfdelingContext
{
    public function __construct(private readonly Security $security, private readonly RequestStack $requests, private readonly AfdelingRepository $afdelingen) {}

    public function huidige(): ?Afdeling
    {
        $persoon = $this->security->getUser();
        if (!$persoon instanceof Persoon) { return null; }
        if ($persoon->isSuperadmin() && $this->requests->getCurrentRequest()?->hasSession()) {
            $id = $this->requests->getSession()->get('superadmin_afdeling');
            if ($id && $afdeling = $this->afdelingen->find($id)) { return $afdeling; }
        }
        return $persoon->getAfdeling();
    }
}

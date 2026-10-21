<?php

namespace App\Controller;

use App\Repository\AanwezigheidslijstRepository;
use App\Repository\TikkingRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends BaseController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(AanwezigheidslijstRepository $lijsten, TikkingRepository $tikkingen): Response
    {
        $persoon = $this->persoon();
        $laatste = $lijsten->laatste($this->afdeling(), 10);
        $metTikking = $persoon->isSysteemaccount() ? [] : $tikkingen->lijstenMetTikking($persoon, array_map(fn ($l) => $l->getId(), $laatste));

        return $this->render('dashboard/index.html.twig', [
            'laatste' => $laatste,
            'met_tikking' => $metTikking,
            'open' => $this->isGranted('ROLE_POST') ? $lijsten->open($this->afdeling(), $persoon->getPost()) : [],
        ]);
    }
}

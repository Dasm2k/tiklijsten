<?php

namespace App\Controller;

use App\Repository\AanwezigheidslijstRepository;
use App\Repository\PersoonRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatistiekController extends BaseController
{
    #[Route('/statistieken', name: 'app_statistieken', methods: ['GET'])]
    public function index(AanwezigheidslijstRepository $lijsten, PersoonRepository $personen): Response
    {
        $afdeling = $this->afdeling();
        $perPeloton = array_map(fn ($r) => ['label' => $r['label'].'e peloton', 'aantal' => (int) $r['aantal']], $lijsten->telPer($afdeling, 'peloton'));
        $perJaar = $lijsten->perJaarEnPeloton($afdeling);

        return $this->render('statistiek/index.html.twig', [
            'rangen' => $personen->telPerRang($afdeling),
            'pelotons' => $perPeloton,
            'interventies' => $lijsten->telPer($afdeling, 'interventie'),
            'gemeenten' => $lijsten->telPer($afdeling, 'gemeente'),
            'per_jaar' => $perJaar,
            'peloton_nummers' => $afdeling->getPelotons(),
        ]);
    }
}

<?php

namespace App\Controller;

use App\Entity\Persoon;
use App\Repository\PersoonRepository;
use App\Repository\TikkingRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Overzicht van tikkingen/prestaties: eigen prestaties, of van iedereen voor personeelsbeheer. */
#[Route('/prestaties')]
#[IsGranted('ROLE_LID')]
final class PrestatieController extends BaseController
{
    private const PER_PAGINA = 25;

    #[Route('', name: 'app_prestaties', methods: ['GET'])]
    public function index(Request $request, PersoonRepository $personen, TikkingRepository $tikkingen): Response
    {
        [$persoon, $van, $tot] = $this->filter($request, $personen);
        $pagina = $this->pagina($request);
        $resultaat = $tikkingen->pagina($persoon, $van, $tot, $pagina, self::PER_PAGINA);

        return $this->render('prestatie/index.html.twig', [
            'persoon' => $persoon,
            'van' => $van,
            'tot' => $tot,
            'tikkingen' => $resultaat,
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil(\count($resultaat) / self::PER_PAGINA)),
            'totaal' => $tikkingen->totaalSeconden($persoon, $van, $tot),
            'personen' => $this->isGranted('ROLE_PERSONEEL') ? $personen->personeel($this->afdeling(), null) : [],
        ]);
    }

    #[Route('/export', name: 'app_prestaties_export', methods: ['GET'])]
    public function export(Request $request, PersoonRepository $personen, TikkingRepository $tikkingen): Response
    {
        [$persoon, $van, $tot] = $this->filter($request, $personen);

        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Prestaties');
        $ws->fromArray(['Lijst', 'Start interventie', 'Interventie', 'Gemeente', 'Begin', 'Einde', 'Prestatie (u:mm)', 'Prestatie (uren)'], null, 'A1');
        $ws->getStyle('A1:H1')->getFont()->setBold(true);
        $rij = 2;
        foreach ($tikkingen->alle($persoon, $van, $tot) as $t) {
            $l = $t->getLijst();
            $sec = $t->getPrestatie();
            $ws->fromArray([
                $l->getLabel(),
                $l->getStart()->format('d/m/Y H:i'),
                $l->getInterventieType()?->getNaam(),
                $l->getGemeente()?->getNaam(),
                $t->getStart()?->format('d/m/Y H:i'),
                $t->getEinde()->format('d/m/Y H:i'),
                sprintf('%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60)),
                round($sec / 3600, 2),
            ], null, 'A'.$rij++);
        }
        foreach (range('A', 'H') as $kolom) {
            $ws->getColumnDimension($kolom)->setAutoSize(true);
        }

        $bestand = sprintf('%d_%s_%s_%s.xlsx', $persoon->getTiknummer(), $persoon->getNaam(), $persoon->getVoornaam(), date('Ymd'));
        $response = new StreamedResponse(static function () use ($sheet): void {
            (new Xlsx($sheet))->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $bestand,
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $bestand),
        ));
        return $response;
    }

    /** @return array{Persoon, ?\DateTimeImmutable, ?\DateTimeImmutable} */
    private function filter(Request $request, PersoonRepository $personen): array
    {
        $persoon = $this->persoon();
        if ($request->query->getInt('persoon') && $this->isGranted('ROLE_PERSONEEL')) {
            $gekozen = $personen->find($request->query->getInt('persoon'));
            $persoon = $this->eigen($gekozen);
        }
        $van = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('van')) ?: null;
        $tot = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('tot')) ?: null;
        return [$persoon, $van, $tot];
    }
}

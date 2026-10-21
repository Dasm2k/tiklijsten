<?php

namespace App\Controller;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Tikking;
use App\Form\LijstType;
use App\Repository\AanwezigheidslijstRepository;
use App\Repository\HerinneringRepository;
use App\Repository\PersoonRepository;
use App\Service\LijstService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/lijsten')]
final class LijstController extends BaseController
{
    private const PER_PAGINA = 25;

    #[Route('', name: 'app_lijst_index', methods: ['GET'])]
    #[IsGranted('ROLE_LIJSTEN')]
    public function index(Request $request, AanwezigheidslijstRepository $lijsten): Response
    {
        $archief = $request->query->getBoolean('archief') && $this->isGranted('ROLE_ARCHIEF');
        $zoek = trim($request->query->getString('zoek')) ?: null;
        $pagina = $this->pagina($request);
        $resultaat = $lijsten->pagina($this->afdeling(), $archief, $pagina, self::PER_PAGINA, $zoek);

        return $this->render('lijst/index.html.twig', [
            'lijsten' => $resultaat,
            'archief' => $archief,
            'zoek' => $zoek,
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil(\count($resultaat) / self::PER_PAGINA)),
        ]);
    }

    #[Route('/{id}', name: 'app_lijst_toon', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_LIJSTEN')]
    public function toon(int $id, AanwezigheidslijstRepository $lijsten): Response
    {
        $lijst = $this->lijst($lijsten, $id);
        return $this->render('lijst/toon.html.twig', ['lijst' => $lijst, 'tikkingen' => LijstService::gesorteerdeTikkingen($lijst)]);
    }

    #[Route('/{id}/bewerken', name: 'app_lijst_bewerken', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_LIJSTEN')]
    public function bewerken(int $id, Request $request, AanwezigheidslijstRepository $lijsten, PersoonRepository $personen, EntityManagerInterface $em): Response
    {
        $lijst = $this->lijst($lijsten, $id);
        $form = $this->createForm(LijstType::class, $lijst, ['afdeling' => $this->afdeling(), 'volledig' => true, 'post_kiezen' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Wijzigingen opgeslagen.');
            return $this->redirectToRoute('app_lijst_bewerken', ['id' => $id]);
        }

        return $this->render('lijst/bewerken.html.twig', [
            'lijst' => $lijst,
            'form' => $form,
            'tikkingen' => LijstService::gesorteerdeTikkingen($lijst),
            'personen' => $personen->keuzeQuery($this->afdeling())->getQuery()->getResult(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /** Tikking manueel toevoegen (bv. iemand die vergeten is in te tikken). */
    #[Route('/{id}/tikking', name: 'app_lijst_tikking_toevoegen', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_LIJSTEN')]
    public function tikkingToevoegen(int $id, Request $request, AanwezigheidslijstRepository $lijsten, PersoonRepository $personen, EntityManagerInterface $em): Response
    {
        $lijst = $this->lijst($lijsten, $id);
        if (!$this->csrfOk($request, 'tikking'.$id)) {
            throw $this->createAccessDeniedException();
        }
        $persoon = $personen->find($request->request->getInt('persoon'));
        $einde = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $request->request->get('einde')) ?: null;
        $start = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $request->request->get('start')) ?: null;
        if (!$persoon || $persoon->getAfdeling()->getId() !== $this->afdeling()->getId() || $persoon->isSysteemaccount()) {
            $this->addFlash('danger', 'Kies een persoon.');
        } elseif (!$einde || $einde < ($start ?? $lijst->getStart())) {
            $this->addFlash('danger', 'Geef een geldig einduur in (na het beginuur).');
        } elseif ($lijst->heeftGetikt($persoon)) {
            $this->addFlash('danger', $persoon->getVolledigeNaam().' staat al op deze lijst.');
        } else {
            $tikking = (new Tikking())->setPersoon($persoon)->setEinde($einde)->setStart($start);
            $lijst->addTikking($tikking);
            $em->persist($tikking);
            $em->flush();
            $this->addFlash('success', 'Tikking toegevoegd voor '.$persoon->getVolledigeNaam().'.');
        }
        return $this->redirectToRoute('app_lijst_bewerken', ['id' => $id]);
    }

    #[Route('/{id}/tikking/{tikking}/verwijderen', name: 'app_lijst_tikking_verwijderen', requirements: ['id' => '\d+', 'tikking' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_LIJSTEN')]
    public function tikkingVerwijderen(int $id, int $tikking, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em): Response
    {
        $lijst = $this->lijst($lijsten, $id);
        $tikking = $em->find(Tikking::class, $tikking);
        if (!$tikking || $tikking->getLijst()->getId() !== $lijst->getId() || !$this->csrfOk($request, 'weg'.$tikking->getId())) {
            throw $this->createNotFoundException();
        }
        $lijst->removeTikking($tikking);
        $em->flush();
        $this->addFlash('success', 'Tikking verwijderd.');
        return $this->redirectToRoute('app_lijst_bewerken', ['id' => $id]);
    }

    #[Route('/{id}/open-sluiten', name: 'app_lijst_open_sluiten', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ARCHIEF')]
    public function openSluiten(int $id, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em): Response
    {
        $lijst = $this->lijst($lijsten, $id);
        if ($this->csrfOk($request, 'status'.$id) && !$lijst->isGearchiveerd()) {
            $lijst->setAfgesloten(!$lijst->isAfgesloten());
            $em->flush();
            $this->addFlash('success', sprintf('Lijst %s is %s.', $lijst->getLabel(), $lijst->isAfgesloten() ? 'gesloten' : 'opnieuw geopend'));
        }
        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_lijst_index'));
    }

    #[Route('/{id}/archiveren', name: 'app_lijst_archiveren', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ARCHIEF')]
    public function archiveren(int $id, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em): Response
    {
        $lijst = $this->lijst($lijsten, $id);
        if ($this->csrfOk($request, 'archief'.$id)) {
            $lijst->setAfgesloten(true)->setGearchiveerd(true);
            $em->flush();
            $this->addFlash('success', 'Lijst '.$lijst->getLabel().' is gearchiveerd.');
        }
        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_lijst_index'));
    }

    /** Afdrukversie met controlevragen; ook beschikbaar voor de tik-pc. */
    #[Route('/{id}/afdrukken', name: 'app_lijst_afdrukken', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function afdrukken(int $id, Request $request, AanwezigheidslijstRepository $lijsten, HerinneringRepository $herinneringen): Response
    {
        if (!$this->isGranted('ROLE_POST') && !$this->isGranted('ROLE_PERSONEEL')) {
            throw $this->createAccessDeniedException();
        }
        $lijst = $this->lijst($lijsten, $id);
        $terug = 'tik' === $request->query->get('terug') && $this->isGranted('ROLE_POST')
            ? $this->generateUrl('app_tik_index')
            : ($request->headers->get('referer') ?: $this->generateUrl('app_dashboard'));

        return $this->render('lijst/afdrukken.html.twig', [
            'lijst' => $lijst,
            'tikkingen' => LijstService::gesorteerdeTikkingen($lijst),
            'herinneringen' => $herinneringen->voorAfdeling($this->afdeling()),
            'terug' => $terug,
        ]);
    }

    private function lijst(AanwezigheidslijstRepository $lijsten, int $id): Aanwezigheidslijst
    {
        return $lijsten->findInAfdeling($this->afdeling(), $id) ?? throw $this->createNotFoundException();
    }
}

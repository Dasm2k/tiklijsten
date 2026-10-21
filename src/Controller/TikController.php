<?php

namespace App\Controller;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Tikking;
use App\Form\LijstType;
use App\Repository\AanwezigheidslijstRepository;
use App\Repository\PersoonRepository;
use App\Service\LijstService;
use App\Service\TikFout;
use App\Service\TikService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** De tik-pc op de post: lijst aanmaken, intikken, afsluiten. */
#[Route('/tikken')]
#[IsGranted(new \Symfony\Component\ExpressionLanguage\Expression('is_granted("ROLE_POST") or is_granted("ROLE_ADMIN")'))]
final class TikController extends BaseController
{
    #[Route('', name: 'app_tik_index', methods: ['GET', 'POST'])]
    public function index(Request $request, LijstService $service, AanwezigheidslijstRepository $lijsten): Response
    {
        $persoon = $this->persoon();
        $lijst = $service->nieuw($persoon);
        $form = $this->createForm(LijstType::class, $lijst, [
            'afdeling' => $this->afdeling(),
            'peloton_kiezen' => null === $persoon->getVastPeloton(),
            'post_kiezen' => null === $persoon->getPost(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $service->opslaanNieuw($lijst);
            $this->addFlash('success', 'Lijst '.$lijst->getLabel().' is aangemaakt.');
            return $this->redirectToRoute('app_tik_lijst', ['id' => $lijst->getId()]);
        }

        return $this->render('tik/index.html.twig', [
            'form' => $form,
            'open' => $lijsten->open($this->afdeling(), $this->isGranted('ROLE_ADMIN') ? null : $persoon->getPost()),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/lijst/{id}', name: 'app_tik_lijst', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lijst(int $id, AanwezigheidslijstRepository $lijsten, PersoonRepository $personen, Request $request): Response
    {
        $lijst = $this->lijstOf404($lijsten, $id);
        return $this->render('tik/lijst.html.twig', [
            'lijst' => $lijst,
            'andere_open' => $lijsten->open($this->afdeling(), $this->isGranted('ROLE_ADMIN') ? null : $this->persoon()->getPost()),
            'peloton' => $lijst->getPeloton() ? $personen->vanPeloton($this->afdeling(), $lijst->getPeloton()) : [],
            'verwijder' => $request->query->getInt('verwijder') ?: null,
            'laatste_tiknummer' => $request->getSession()->getFlashBag()->get('tiknummer')[0] ?? '',
        ]);
    }

    #[Route('/lijst/{id}/in', name: 'app_tik_in', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tikIn(int $id, Request $request, AanwezigheidslijstRepository $lijsten, TikService $tik): Response
    {
        $lijst = $this->lijstOf404($lijsten, $id);
        if (!$this->csrfOk($request, 'tik'.$lijst->getId())) {
            $this->addFlash('danger', 'Het formulier is verlopen, probeer opnieuw.');
            return $this->redirectToRoute('app_tik_lijst', ['id' => $id]);
        }
        try {
            $tikking = $tik->tikIn($lijst, (string) $request->request->get('tiknummer'), (string) $request->request->get('code'), '2' === $request->request->get('beginuur'));
            $this->addFlash('success', $tikking->getPersoon()->getVolledigeNaam().' is ingetikt.');
        } catch (TikFout $e) {
            $this->addFlash('danger', $e->getMessage());
            if ('Code niet juist.' === $e->getMessage()) {
                $this->addFlash('tiknummer', (string) $request->request->get('tiknummer'));
            }
        }
        return $this->redirectToRoute('app_tik_lijst', ['id' => $id]);
    }

    #[Route('/lijst/{id}/tikking/{tikking}/verwijderen', name: 'app_tik_verwijder', requirements: ['id' => '\d+', 'tikking' => '\d+'], methods: ['POST'])]
    public function verwijder(int $id, int $tikking, Request $request, AanwezigheidslijstRepository $lijsten, TikService $tik, EntityManagerInterface $em): Response
    {
        $lijst = $this->lijstOf404($lijsten, $id);
        $tikking = $em->find(Tikking::class, $tikking);
        if (!$tikking || $tikking->getLijst()?->getId() !== $lijst->getId() || !$this->csrfOk($request, 'verwijder'.$tikking->getId())) {
            throw $this->createNotFoundException();
        }
        try {
            $naam = $tikking->getPersoon()->getVolledigeNaam();
            $tik->verwijder($lijst, $tikking, (string) $request->request->get('code'));
            $this->addFlash('success', 'Tikking van '.$naam.' is verwijderd.');
        } catch (TikFout $e) {
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('app_tik_lijst', ['id' => $id, 'verwijder' => $tikking->getId()]);
        }
        return $this->redirectToRoute('app_tik_lijst', ['id' => $id]);
    }

    #[Route('/lijst/{id}/tweede-beginuur', name: 'app_tik_tweede_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tweedeStart(int $id, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        $lijst = $this->lijstOf404($lijsten, $id);
        if (!$this->csrfOk($request, 'tweede'.$lijst->getId()) || $lijst->getTweedeStart() || $lijst->isAfgesloten()) {
            return $this->redirectToRoute('app_tik_lijst', ['id' => $id]);
        }
        $waarde = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $request->request->get('tweede_start')) ?: null;
        if (!$waarde || $waarde > new \DateTimeImmutable('+5 minutes') || $waarde < $lijst->getStart()) {
            $this->addFlash('danger', 'Ongeldig tweede beginuur: het moet na het eerste beginuur liggen en mag niet in de toekomst zijn.');
        } else {
            $lijst->setTweedeStart($waarde);
            $em->flush();
            $this->addFlash('success', 'Tweede beginuur toegevoegd.');
        }
        return $this->redirectToRoute('app_tik_lijst', ['id' => $id]);
    }

    #[Route('/lijst/{id}/afsluiten', name: 'app_tik_afsluiten', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function afsluiten(int $id, Request $request, AanwezigheidslijstRepository $lijsten, LijstService $service): Response
    {
        $lijst = $this->lijstOf404($lijsten, $id);
        if (!$this->csrfOk($request, 'afsluiten'.$lijst->getId()) || $lijst->isAfgesloten()) {
            return $this->redirectToRoute('app_tik_lijst', ['id' => $id]);
        }
        $aantal = $service->sluitAf($lijst);
        $this->addFlash('success', sprintf('Lijst %s is afgesloten%s.', $lijst->getLabel(), $aantal ? ' en gemaild naar '.$aantal.' ontvanger(s)' : ''));
        // Afdrukken en daarna terug naar het tikscherm.
        return $this->redirectToRoute('app_lijst_afdrukken', ['id' => $id, 'terug' => 'tik']);
    }

    private function lijstOf404(AanwezigheidslijstRepository $lijsten, int $id): Aanwezigheidslijst
    {
        $lijst = $lijsten->findInAfdeling($this->afdeling(), $id);
        if (!$lijst) {
            throw $this->createNotFoundException();
        }
        // Een tik-pc ziet enkel de lijsten van zijn eigen post.
        if (!$this->isGranted('ROLE_ADMIN') && $this->persoon()->getPost() && $lijst->getPost()?->getId() !== $this->persoon()->getPost()->getId()) {
            throw $this->createNotFoundException();
        }
        return $lijst;
    }
}

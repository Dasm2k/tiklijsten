<?php

namespace App\Controller;

use App\Entity\Foutmelding;
use App\Entity\Persoon;
use App\Enum\FoutStatus;
use App\Form\BerichtType;
use App\Form\FoutmeldingType;
use App\Repository\FoutmeldingRepository;
use App\Repository\PersoonRepository;
use App\Service\FoutmeldingService;
use App\Service\FoutmeldingIndienenService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotNull;

#[Route('/foutmeldingen')]
#[IsGranted('ROLE_LID')]
final class FoutmeldingController extends BaseController
{
    #[Route('', name: 'app_fout_index', methods: ['GET'])]
    public function index(Request $request, FoutmeldingRepository $fouten): Response
    {
        $this->moduleActief();
        // Opruimen van afgesloten meldingen gebeurt via app:foutmeldingen:opruimen (cron).
        // Wie foutmeldingen opvolgt, ziet standaard enkel de eigen meldingen (zoals vroeger de "filter").
        $kanFilteren = $this->isGranted('ROLE_FOUTEN');
        $enkelEigen = $kanFilteren && !$request->query->getBoolean('alle');

        return $this->render('fout/index.html.twig', [
            'fouten' => $fouten->overzicht($this->afdeling(), $enkelEigen ? $this->persoon() : null),
            'enkel_eigen' => $enkelEigen,
            'kan_filteren' => $kanFilteren,
        ]);
    }

    #[Route('/nieuw', name: 'app_fout_nieuw', methods: ['GET', 'POST'])]
    public function nieuw(Request $request, FoutmeldingIndienenService $service): Response
    {
        $this->moduleActief();
        $persoon = $this->persoon();
        $form = $this->createForm(FoutmeldingType::class, null, ['afdeling' => $this->afdeling(), 'email_vragen' => !$persoon->getEmail()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fout = $service->indienen($persoon, $form->getData(), $this->afdeling());
            if (!$fout) {
                $this->addFlash('success', 'De melding van het kadaver is doorgestuurd.');
                return $this->redirectToRoute('app_fout_index');
            }

            $this->addFlash('success', 'Je foutmelding is verstuurd. Bedankt!');
            return $this->redirectToRoute('app_fout_toon', ['id' => $fout->getId()]);
        }

        return $this->render('fout/nieuw.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}', name: 'app_fout_toon', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function toon(int $id, FoutmeldingRepository $fouten): Response
    {
        return $this->render('fout/toon.html.twig', ['fout' => $this->fout($fouten, $id)]);
    }

    #[Route('/{id}/opnemen', name: 'app_fout_opnemen', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_FOUTEN')]
    public function opnemen(int $id, Request $request, FoutmeldingRepository $fouten, FoutmeldingService $service): Response
    {
        $fout = $this->fout($fouten, $id);
        if ($this->csrfOk($request, 'opnemen'.$id) && !$fout->getStatus()->isAfgesloten()) {
            $service->opnemen($fout, $this->persoon());
            $this->addFlash('success', 'Je volgt deze foutmelding nu op.');
        }
        return $this->redirectToRoute('app_fout_toon', ['id' => $id]);
    }

    /** Extra info, buitendienst, definitief buitendienst en afwerken: allemaal een bericht + statuswijziging. */
    #[Route('/{id}/{actie}', name: 'app_fout_actie', requirements: ['id' => '\d+', 'actie' => 'extra-info|buitendienst|definitief|afwerken'], methods: ['GET', 'POST'])]
    public function actie(int $id, string $actie, Request $request, FoutmeldingRepository $fouten, FoutmeldingService $service): Response
    {
        $fout = $this->fout($fouten, $id);
        $nodigeRol = ['extra-info' => 'ROLE_LID', 'buitendienst' => 'ROLE_FOUTEN', 'afwerken' => 'ROLE_FOUTEN', 'definitief' => 'ROLE_PERSONEEL'][$actie];
        $this->denyAccessUnlessGranted($nodigeRol);
        if ($fout->getStatus()->isAfgesloten() && !$this->isGranted('ROLE_PERSONEEL')) {
            $this->addFlash('warning', 'Deze foutmelding is al afgesloten.');
            return $this->redirectToRoute('app_fout_toon', ['id' => $id]);
        }

        $titels = ['extra-info' => 'Extra info toevoegen', 'buitendienst' => 'Buitendienst', 'definitief' => 'Definitief buitendienst', 'afwerken' => 'Afgewerkt'];
        $form = $this->createForm(BerichtType::class, null, ['verplicht' => 'afwerken' !== $actie, 'iedereen_optie' => 'buitendienst' === $actie]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $tekst = trim((string) $form->get('bericht')->getData());
            $door = $this->persoon();
            match ($actie) {
                'extra-info' => $service->extraInfo($fout, $door, $tekst),
                'buitendienst' => $service->buitendienst($fout, $door, $tekst, (bool) $form->get('iedereen')->getData()),
                'definitief' => $service->definitiefBuitendienst($fout, $door, $tekst),
                'afwerken' => $service->afwerken($fout, $door, $tekst),
            };
            $this->addFlash('success', 'Foutmelding bijgewerkt.');
            return $this->redirectToRoute('app_fout_toon', ['id' => $id]);
        }
        return $this->render('fout/actie.html.twig', ['fout' => $fout, 'form' => $form, 'titel' => $titels[$actie]], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/toewijzen', name: 'app_fout_toewijzen', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PERSONEEL')]
    public function toewijzen(int $id, Request $request, FoutmeldingRepository $fouten, FoutmeldingService $service, PersoonRepository $personen): Response
    {
        $fout = $this->fout($fouten, $id);
        $form = $this->createFormBuilder()
            ->add('opvolger', EntityType::class, [
                'class' => Persoon::class,
                'query_builder' => $personen->keuzeQuery($this->afdeling()),
                'choice_label' => fn (Persoon $p) => $p->getVolledigeNaam(),
                'placeholder' => '— kies —',
                'constraints' => [new NotNull(message: 'Kies iemand.')],
                'label' => 'Aan wie wens je de foutmelding toe te wijzen?',
            ])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $service->toewijzen($fout, $form->get('opvolger')->getData(), $this->persoon());
            $this->addFlash('success', 'Foutmelding toegewezen.');
            return $this->redirectToRoute('app_fout_toon', ['id' => $id]);
        }
        return $this->render('fout/actie.html.twig', ['fout' => $fout, 'form' => $form, 'titel' => 'Toewijzen'], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/status', name: 'app_fout_status', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PERSONEEL')]
    public function status(int $id, Request $request, FoutmeldingRepository $fouten, FoutmeldingService $service): Response
    {
        $fout = $this->fout($fouten, $id);
        $form = $this->createFormBuilder(['status' => $fout->getStatus()])
            ->add('status', EnumType::class, [
                'class' => FoutStatus::class,
                'choice_label' => fn (FoutStatus $s) => $s->label().(FoutStatus::NIEUW === $s ? ' (wist de opvolger)' : ''),
                'expanded' => true,
            ])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var FoutStatus $nieuw */
            $nieuw = $form->get('status')->getData();
            return match ($nieuw) {
                FoutStatus::NIEUW => (function () use ($service, $fout, $id) {
                    $service->terugzetten($fout, $this->persoon());
                    $this->addFlash('success', 'Foutmelding teruggezet.');
                    return $this->redirectToRoute('app_fout_toon', ['id' => $id]);
                })(),
                FoutStatus::TOEGEWEZEN => $this->redirectToRoute('app_fout_toewijzen', ['id' => $id]),
                FoutStatus::EXTRA_INFO => $this->redirectToRoute('app_fout_actie', ['id' => $id, 'actie' => 'extra-info']),
                FoutStatus::BUITENDIENST => $this->redirectToRoute('app_fout_actie', ['id' => $id, 'actie' => 'buitendienst']),
                FoutStatus::DEFINITIEF_BUITENDIENST => $this->redirectToRoute('app_fout_actie', ['id' => $id, 'actie' => 'definitief']),
                FoutStatus::AFGEWERKT => $this->redirectToRoute('app_fout_actie', ['id' => $id, 'actie' => 'afwerken']),
            };
        }
        return $this->render('fout/actie.html.twig', ['fout' => $fout, 'form' => $form, 'titel' => 'Status wijzigen']);
    }

    private function fout(FoutmeldingRepository $fouten, int $id): Foutmelding
    {
        $this->moduleActief();
        return $fouten->findInAfdeling($this->afdeling(), $id) ?? throw $this->createNotFoundException();
    }

    private function moduleActief(): void
    {
        if (!$this->afdeling()->isFoutmeldingenActief()) {
            throw $this->createNotFoundException();
        }
    }
}

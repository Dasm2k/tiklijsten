<?php

namespace App\Controller;

use App\Entity\Persoon;
use App\Enum\Rol;
use App\Form\PersoonType;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/personeel')]
#[IsGranted('ROLE_PERSONEEL')]
final class PersoneelController extends BaseController
{
    #[Route('', name: 'app_personeel_index', methods: ['GET'])]
    public function index(Request $request, PersoonRepository $personen): Response
    {
        $sort = $request->query->getString('sort', 'naam');
        $sort = isset(PersoonRepository::SORTEERVELDEN[$sort]) ? $sort : 'naam';
        $richting = 'DESC' === strtoupper($request->query->getString('richting', 'ASC')) ? 'DESC' : 'ASC';
        $inactief = $request->query->getBoolean('inactief');
        $zoek = trim(mb_substr($request->query->getString('zoek'), 0, 200));

        return $this->render('personeel/index.html.twig', [
            'personen' => $personen->personeel($this->afdeling(), !$inactief, $sort, $richting, $zoek),
            'sort' => $sort,
            'richting' => $richting,
            'inactief' => $inactief,
            'zoek' => $zoek,
        ]);
    }

    #[Route('/nieuw', name: 'app_personeel_nieuw', methods: ['GET', 'POST'])]
    public function nieuw(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $persoon = (new Persoon())->setAfdeling($this->afdeling());
        $form = $this->maakForm($persoon, true);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->opslaan($form, $persoon, $hasher)) {
            $em->persist($persoon);
            $em->flush();
            $this->addFlash('success', $persoon->getVolledigeNaam().' is toegevoegd.');
            return $this->redirectToRoute('app_personeel_index');
        }
        return $this->render('personeel/form.html.twig', ['form' => $form, 'persoon' => $persoon], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/bewerken', name: 'app_personeel_bewerken', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function bewerken(int $id, Request $request, PersoonRepository $personen, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $persoon = $this->eigen($personen->find($id));
        if ($persoon->isSysteemaccount() || $persoon->getRol()->gewicht() > $this->persoon()->getRol()->gewicht()) {
            throw $this->createAccessDeniedException('Je kan dit account niet bewerken.');
        }
        $form = $this->maakForm($persoon, false);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->opslaan($form, $persoon, $hasher)) {
            $em->flush();
            $this->addFlash('success', 'Gegevens van '.$persoon->getVolledigeNaam().' zijn opgeslagen.');
            return $this->redirectToRoute('app_personeel_index');
        }
        return $this->render('personeel/form.html.twig', ['form' => $form, 'persoon' => $persoon], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /** Personeel wordt nooit verwijderd (historiek blijft bewaard), enkel op inactief gezet. */
    #[Route('/{id}/actief', name: 'app_personeel_actief', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function actief(int $id, Request $request, PersoonRepository $personen, EntityManagerInterface $em): Response
    {
        $persoon = $this->eigen($personen->find($id));
        if ($persoon->isSysteemaccount() || !$this->csrfOk($request, 'actief'.$id) || $persoon->getId() === $this->persoon()->getId()
            || $persoon->getRol()->gewicht() > $this->persoon()->getRol()->gewicht()) {
            throw $this->createAccessDeniedException();
        }
        $persoon->setActief(!$persoon->isActief());
        $em->flush();
        $this->addFlash('success', sprintf('%s is %s.', $persoon->getVolledigeNaam(), $persoon->isActief() ? 'opnieuw actief' : 'op inactief gezet'));
        return $this->redirectToRoute('app_personeel_index', ['inactief' => $persoon->isActief() ? 1 : null]);
    }

    private function maakForm(Persoon $persoon, bool $nieuw): FormInterface
    {
        $eigenRol = $this->persoon()->getRol();
        return $this->createForm(PersoonType::class, $persoon, [
            'afdeling' => $this->afdeling(),
            'code_verplicht' => $nieuw,
            'max_rol' => Rol::POST === $eigenRol ? Rol::LID : $eigenRol,
        ]);
    }

    private function opslaan(FormInterface $form, Persoon $persoon, UserPasswordHasherInterface $hasher): bool
    {
        $code = $form->get('nieuweCode')->getData();
        if ($code) {
            $persoon->setCode($hasher->hashPassword($persoon, $code));
        } elseif ('' === $persoon->getCode()) {
            $form->get('nieuweCode')->addError(new \Symfony\Component\Form\FormError('Geef een code in.'));
            return false;
        }
        return true;
    }
}

<?php

namespace App\Controller;

use App\Entity\Persoon;
use App\Enum\Rol;
use App\Form\AfdelingType;
use App\Form\SysteemaccountType;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/instellingen')]
#[IsGranted('ROLE_BEHEER')]
final class InstellingenController extends BaseController
{
    #[Route('', name: 'app_instellingen', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, PersoonRepository $personen): Response
    {
        $afdeling = $em->find(\App\Entity\Afdeling::class, $this->afdeling()->getId());
        $form = $this->createForm(AfdelingType::class, $afdeling);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Instellingen opgeslagen.');
            return $this->redirectToRoute('app_instellingen');
        }
        return $this->render('instellingen/index.html.twig', [
            'form' => $form,
            'accounts' => $personen->findBy(['afdeling' => $afdeling, 'systeemaccount' => true], ['tiknummer' => 'ASC']),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/account/nieuw', name: 'app_systeemaccount_nieuw', methods: ['GET', 'POST'])]
    #[Route('/account/{id}', name: 'app_systeemaccount_bewerken', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function account(Request $request, PersoonRepository $personen, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, ?int $id = null): Response
    {
        $account = $id
            ? $this->eigen($personen->find($id))
            : (new Persoon())->setAfdeling($this->afdeling())->setSysteemaccount(true)->setRol(Rol::POST)->setSessieduur(0);
        if (!$account->isSysteemaccount()) {
            throw $this->createNotFoundException();
        }
        // Enkel een administrator mag een administratoraccount aanpassen of aanmaken.
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (Rol::ADMIN === $account->getRol() && !$isAdmin) {
            throw $this->createAccessDeniedException();
        }
        $form = $this->createForm(SysteemaccountType::class, $account, [
            'afdeling' => $this->afdeling(),
            'code_verplicht' => !$id,
            'admin_toegestaan' => $isAdmin,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($code = $form->get('nieuweCode')->getData()) {
                $account->setCode($hasher->hashPassword($account, $code));
            }
            if ('' === $account->getCode()) {
                $this->addFlash('danger', 'Geef een code in.');
            } else {
                $em->persist($account);
                $em->flush();
                $this->addFlash('success', 'Account opgeslagen.');
                return $this->redirectToRoute('app_instellingen');
            }
        }
        return $this->render('instellingen/account.html.twig', ['form' => $form, 'account' => $account], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}

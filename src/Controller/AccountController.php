<?php

namespace App\Controller;

use App\Form\CodeWijzigenType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AccountController extends BaseController
{
    #[Route('/account/code', name: 'app_account_code', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_LID')]
    public function code(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        if ($this->persoon()->isSuperadmin()) { throw $this->createAccessDeniedException('Wijzig het superadminwachtwoord via app:superadmin:instellen.'); }
        $form = $this->createForm(CodeWijzigenType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $persoon = $this->persoon();
            $persoon->setCode($hasher->hashPassword($persoon, $form->get('nieuweCode')->getData()));
            $em->flush();
            $this->addFlash('success', 'Je code is gewijzigd. Gebruik voortaan je nieuwe code om aan te melden en in te tikken.');
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('account/code.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}

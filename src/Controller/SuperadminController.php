<?php
namespace App\Controller;

use App\Repository\AfdelingRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SuperadminController extends BaseController
{
    #[Route('/superadmin/login', name: 'app_superadmin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        return $this->render('superadmin/login.html.twig', ['fout' => $auth->getLastAuthenticationError(), 'email' => $auth->getLastUsername()]);
    }

    #[Route('/superadmin/afdelingen', name: 'app_superadmin_afdelingen', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPERADMIN')]
    public function afdelingen(Request $request, AfdelingRepository $afdelingen): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->csrfOk($request, 'afdeling_wisselen')) { throw $this->createAccessDeniedException(); }
            $afdeling = $afdelingen->find($request->request->getInt('afdeling'));
            if (!$afdeling) { throw $this->createNotFoundException(); }
            $request->getSession()->set('superadmin_afdeling', $afdeling->getId());
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('superadmin/afdelingen.html.twig', ['afdelingen' => $afdelingen->findAllGesorteerd()]);
    }
}

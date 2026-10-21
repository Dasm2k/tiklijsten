<?php

namespace App\Controller;

use App\Entity\Persoon;
use App\Repository\AfdelingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class PortaalController extends AbstractController
{
    #[Route('/', name: 'app_portaal', methods: ['GET'])]
    public function portaal(AfdelingRepository $afdelingen): Response
    {
        if ($this->getUser() instanceof Persoon) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('portaal/index.html.twig', ['afdelingen' => $afdelingen->findAllGesorteerd()]);
    }

    #[Route('/login/{afdeling}', name: 'app_login', requirements: ['afdeling' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function login(string $afdeling, AfdelingRepository $afdelingen, AuthenticationUtils $auth): Response
    {
        $entiteit = $afdelingen->findOneBy(['slug' => $afdeling]);
        if (!$entiteit) {
            throw $this->createNotFoundException();
        }
        $huidig = $this->getUser();
        if ($huidig instanceof Persoon && $huidig->getAfdeling()->getSlug() === $afdeling) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('portaal/login.html.twig', [
            'afdeling' => $entiteit,
            'laatste_tiknummer' => $auth->getLastUsername(),
            'fout' => $auth->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Wordt afgehandeld door de firewall.');
    }
}

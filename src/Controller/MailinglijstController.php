<?php

namespace App\Controller;

use App\Entity\Mailinglijst;
use App\Form\MailinglijstType;
use App\Repository\MailinglijstRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mailinglijsten')]
#[IsGranted('ROLE_BEHEER')]
final class MailinglijstController extends BaseController
{
    #[Route('', name: 'app_mailing_index', methods: ['GET'])]
    public function index(MailinglijstRepository $lijsten): Response
    {
        return $this->render('mailing/index.html.twig', ['lijsten' => $lijsten->voorAfdeling($this->afdeling())]);
    }

    #[Route('/nieuw', name: 'app_mailing_nieuw', methods: ['GET', 'POST'])]
    #[Route('/{id}/bewerken', name: 'app_mailing_bewerken', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function form(Request $request, MailinglijstRepository $lijsten, EntityManagerInterface $em, ?int $id = null): Response
    {
        $lijst = $id ? $this->eigen($lijsten->find($id)) : (new Mailinglijst())->setAfdeling($this->afdeling());
        $form = $this->createForm(MailinglijstType::class, $lijst, ['afdeling' => $this->afdeling()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($lijst);
            $em->flush();
            $this->addFlash('success', 'Mailinglijst "'.$lijst->getNaam().'" is opgeslagen.');
            return $this->redirectToRoute('app_mailing_index');
        }
        return $this->render('mailing/form.html.twig', ['form' => $form, 'lijst' => $lijst], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/verwijderen', name: 'app_mailing_verwijderen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verwijderen(int $id, Request $request, MailinglijstRepository $lijsten, EntityManagerInterface $em): Response
    {
        $lijst = $this->eigen($lijsten->find($id));
        if ($lijst->isSysteemlijst() || !$this->csrfOk($request, 'weg'.$id)) {
            $this->addFlash('danger', 'Deze mailinglijst kan niet verwijderd worden.');
        } else {
            $em->remove($lijst);
            $em->flush();
            $this->addFlash('success', 'Mailinglijst verwijderd.');
        }
        return $this->redirectToRoute('app_mailing_index');
    }
}

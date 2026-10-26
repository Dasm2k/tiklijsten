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
            'mail_log' => $em->getConnection()->fetchAllAssociative('SELECT m.*, l.nummer FROM afsluitmail m JOIN aanwezigheidslijst l ON l.id = m.lijst_id WHERE m.afdeling_id = ? ORDER BY m.id DESC LIMIT 50', [$afdeling->getId()]),
            'chauffeur_posten' => $em->getRepository(\App\Entity\Post::class)->findBy([], ['naam' => 'ASC']),
            'chauffeur_instellingen' => $this->chauffeurInstellingen($em),
            'form' => $form,
            'accounts' => $personen->findBy(['afdeling' => $afdeling, 'systeemaccount' => true], ['tiknummer' => 'ASC']),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/afsluitmail/{id}/opnieuw', name: 'app_instellingen_mail_opnieuw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mailOpnieuw(int $id, Request $request, EntityManagerInterface $em, \App\Service\LijstService $service): Response
    {
        if (!$this->csrfOk($request, 'afsluitmail'.$id)) { throw $this->createAccessDeniedException(); }
        $rij = $em->getConnection()->fetchAssociative('SELECT * FROM afsluitmail WHERE id = ? AND afdeling_id = ?', [$id, $this->afdeling()->getId()]);
        if (!$rij || 'verzonden' === $rij['status']) { throw $this->createNotFoundException(); }
        $lijst = $em->find(\App\Entity\Aanwezigheidslijst::class, (int) $rij['lijst_id']);
        if (!$lijst || $lijst->getAfdeling()->getId() !== $this->afdeling()->getId()) { throw $this->createNotFoundException(); }
        $aantal = $service->verzendAfsluitmail($lijst);
        $this->addFlash($aantal > 0 ? 'success' : 'warning', $aantal > 0 ? 'Mail aan mailserver aangeboden.' : 'Mail niet verzonden. Controleer het logboek.');
        return $this->redirectToRoute('app_instellingen');
    }

    private function chauffeurInstellingen(EntityManagerInterface $em): array
    {
        $waarden = [];
        foreach ($em->getRepository(\App\Entity\ChauffeurInstelling::class)->findBy(['afdeling' => $this->afdeling()]) as $instelling) {
            $waarden[$instelling->getPost()->getId()] = $instelling->isActief() ? 'aan' : 'uit';
        }
        return $waarden;
    }

    #[Route('/chauffeurs', name: 'app_instellingen_chauffeurs', methods: ['POST'])]
    public function chauffeurs(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->csrfOk($request, 'chauffeur_instellingen')) { throw $this->createAccessDeniedException(); }
        foreach ($request->request->all('posten') as $id => $waarde) {
            if (!ctype_digit((string) $id) || !in_array($waarde, ['standaard', 'aan', 'uit'], true)) {
                throw $this->createNotFoundException();
            }
            $post = $em->find(\App\Entity\Post::class, (int) $id);
            if (!$post) { throw $this->createNotFoundException(); }
            $instelling = $em->getRepository(\App\Entity\ChauffeurInstelling::class)->findOneBy(['afdeling' => $this->afdeling(), 'post' => $post]);
            if ('standaard' === $waarde) {
                if ($instelling) { $em->remove($instelling); }
            } else {
                if (!$instelling) {
                    $em->getFilters()->disable('soft_delete');
                    try { $instelling = $em->getRepository(\App\Entity\ChauffeurInstelling::class)->findOneBy(['afdeling'=>$this->afdeling(),'post'=>$post]); }
                    finally { $em->getFilters()->enable('soft_delete'); }
                    if ($instelling) { $instelling->herstel(); }
                }
                $instelling ??= (new \App\Entity\ChauffeurInstelling())->setAfdeling($this->afdeling())->setPost($post);
                $instelling->setActief('aan' === $waarde);
                $em->persist($instelling);
            }
        }
        $em->flush();
        $this->addFlash('success', 'Chauffeursinstellingen per post opgeslagen.');
        return $this->redirectToRoute('app_instellingen');
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
        if ($account->isSuperadmin()) { throw $this->createAccessDeniedException('Superadmin aanpassen via het consolecommando.'); }
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

<?php
namespace App\Controller;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Tikking;
use App\Repository\AanwezigheidslijstRepository;
use App\Service\SpecialeTikService;
use App\Service\SpecialeLijstRechten;
use App\Service\LijstService;
use App\Service\TikFout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/speciale-lijsten')]
#[IsGranted('ROLE_LID')]
final class SpecialeLijstController extends BaseController
{
    #[Route('', name: 'app_speciaal_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $status = $request->query->getString('status');
        if (!in_array($status, ['', 'open', 'afgesloten'], true)) { $status = ''; }
        $qb = $em->getRepository(Aanwezigheidslijst::class)->createQueryBuilder('l')->where('l.afdeling = :afd AND l.speciaal = true AND l.gearchiveerd = false')->setParameter('afd',$this->afdeling())->orderBy('l.afgesloten','ASC')->addOrderBy('l.start','DESC')->addOrderBy('l.id','DESC');
        if (!$this->isGranted('ROLE_BEHEER') && $this->persoon()->getPost()) { $qb->andWhere('l.post = :post')->setParameter('post',$this->persoon()->getPost()); }
        if ('' !== $status) { $qb->andWhere('l.afgesloten = :afgesloten')->setParameter('afgesloten', 'afgesloten' === $status); }
        return $this->render('speciaal/index.html.twig',['lijsten'=>$qb->getQuery()->getResult(), 'status'=>$status]);
    }
    #[Route('/{id}', name: 'app_speciaal_toon', requirements: ['id'=>'\d+'], methods: ['GET','POST'])]
    public function toon(int $id, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em, SpecialeTikService $service, SpecialeLijstRechten $rechten): Response
    {
        $lijst = $this->kies($lijsten,$id);
        if ($request->isMethod('POST')) {
            if (!$this->csrfOk($request,'speciaal'.$id)) { throw $this->createAccessDeniedException(); }
            try {
                if ($request->request->get('actie', 'begin') !== 'begin') { throw new TikFout('Registreer het einduur via Aanwezigen.'); }
                $service->registreer($lijst,$this->persoon(),'begin',SpecialeTikService::tijd((string)$request->request->get('moment')));
                $this->addFlash('success','Je registratie is opgeslagen.');
            } catch (TikFout $e) { $this->addFlash('danger',$e->getMessage()); }
            return $this->redirectToRoute('app_speciaal_toon',['id'=>$id]);
        }
        return $this->render('speciaal/toon.html.twig',['lijst'=>$lijst,'registraties'=>$em->getRepository(Tikking::class)->findBy($rechten->magAfsluiten($lijst,$this->persoon()) ? ['lijst'=>$lijst] : ['lijst'=>$lijst,'persoon'=>$this->persoon()],['persoon'=>'ASC','volgnummer'=>'ASC']), 'mag_afsluiten'=>$rechten->magAfsluiten($lijst,$this->persoon())]);
    }
    #[Route('/{id}/tikking/{tikking}', name: 'app_speciaal_wijzig', requirements: ['id'=>'\d+','tikking'=>'\d+'], methods: ['POST'])]
    public function wijzig(int $id, int $tikking, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em, SpecialeTikService $service, SpecialeLijstRechten $rechten): Response
    {
        $lijst=$this->kies($lijsten,$id);$t=$em->find(Tikking::class,$tikking);
        if (!$t || $t->getLijst()?->getId() !== $id || ($t->getPersoon()?->getId() !== $this->persoon()->getId() && !$rechten->magAfsluiten($lijst,$this->persoon())) || !$this->csrfOk($request,'eigen_tijden'.$tikking)) { throw $this->createAccessDeniedException(); }
        try {
            $einde=trim((string)$request->request->get('einde'));
            $service->wijzig($t,$this->persoon(),SpecialeTikService::tijd((string)$request->request->get('start')),'' === $einde ? null : SpecialeTikService::tijd($einde));
            $this->addFlash('success','De tijden zijn aangepast.');
        } catch (TikFout $e) { $this->addFlash('danger',$e->getMessage()); }
        return $this->redirectToRoute('app_speciaal_toon',['id'=>$id]);
    }
    #[Route('/{id}/afsluiten', name: 'app_speciaal_afsluiten', requirements: ['id'=>'\d+'], methods: ['POST'])]
    public function afsluiten(int $id, Request $request, AanwezigheidslijstRepository $lijsten, LijstService $service, SpecialeLijstRechten $rechten): Response
    {
        $lijst=$this->kies($lijsten,$id);
        if (!$this->csrfOk($request,'speciaal_afsluiten'.$id) || !$rechten->magAfsluiten($lijst,$this->persoon())) { throw $this->createAccessDeniedException(); }
        try { $service->sluitAf($lijst,$this->persoon()); $this->addFlash('success','De speciale lijst is afgesloten.'); }
        catch (TikFout $e) { $this->addFlash('warning',$e->getMessage()); }
        return $this->redirectToRoute('app_speciaal_toon',['id'=>$id]);
    }
    #[Route('/{id}/heropenen', name: 'app_speciaal_heropenen', requirements: ['id'=>'\\d+'], methods: ['POST'])]
    public function heropenen(int $id, Request $request, AanwezigheidslijstRepository $lijsten, EntityManagerInterface $em, SpecialeLijstRechten $rechten): Response
    {
        $lijst=$this->kies($lijsten,$id);
        if (!$this->csrfOk($request,'speciaal_heropenen'.$id) || !$rechten->magAfsluiten($lijst,$this->persoon())) { throw $this->createAccessDeniedException(); }
        if ($lijst->isGearchiveerd()) { $this->addFlash('warning','Laat een beheerder eerst het archief controleren.'); }
        else {
            $em->wrapInTransaction(function () use ($em,$lijst): void { $em->refresh($lijst,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE); $lijst->setAfgesloten(false); });
            $this->addFlash('success','De speciale lijst is heropend voor correcties.');
        }
        return $this->redirectToRoute('app_speciaal_toon',['id'=>$id]);
    }

    private function kies(AanwezigheidslijstRepository $lijsten,int $id): Aanwezigheidslijst
    {
        $lijst=$lijsten->findInAfdeling($this->afdeling(),$id);
        if (!$lijst || !$lijst->isSpeciaal() || (!$this->isGranted('ROLE_BEHEER') && $this->persoon()->getPost() && $lijst->getPost()?->getId() !== $this->persoon()->getPost()->getId())) { throw $this->createNotFoundException(); }
        return $lijst;
    }
}

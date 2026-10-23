<?php
namespace App\Controller;

use App\Entity\Handeling;
use App\Entity\Tikking;
use App\Service\AuditLog;
use App\Service\SpecialeTikService;
use App\Service\TikFout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/beheer/historiek')]
#[IsGranted('ROLE_BEHEER')]
final class HandelingController extends BaseController
{
    // Types with a delete action in the application; no arbitrary class names from requests.
    private const TYPES = [
        'tikking'=>Tikking::class, 'mailinglijst'=>\App\Entity\Mailinglijst::class,
        'materiaal'=>\App\Entity\Materiaal::class, 'herinnering'=>\App\Entity\Herinnering::class,
        'foutmelding'=>\App\Entity\Foutmelding::class, 'foutbericht'=>\App\Entity\Foutbericht::class,
        'chauffeurinstelling'=>\App\Entity\ChauffeurInstelling::class,
    ];
    #[Route('', name: 'app_handelingen', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $qb=$em->getRepository(Handeling::class)->createQueryBuilder('h')->orderBy('h.id','DESC')->setMaxResults(50)->setFirstResult(($this->pagina($request)-1)*50);
        $qb->where('h.afdelingId = :afd')->setParameter('afd',$this->afdeling()->getId());
        if ($request->query->getBoolean('systeem') && $this->isGranted('ROLE_SUPERADMIN')) { $qb->orWhere('h.afdelingId IS NULL'); }
        $actie=$request->query->getString('actie');if ('' !== $actie) { $qb->andWhere('h.actie = :actie')->setParameter('actie',$actie); }
        return $this->render('historiek/index.html.twig',['handelingen'=>$qb->getQuery()->getResult(),'pagina'=>$this->pagina($request),'actie'=>$actie]);
    }
    #[Route('/prullenbak', name: 'app_prullenbak', methods: ['GET'])]
    public function prullenbak(EntityManagerInterface $em, AuditLog $log): Response
    {
        $items=[];$em->getFilters()->disable('soft_delete');
        try {
            foreach (self::TYPES as $key=>$class) {
                // At most the latest 100 per type, scoped in SQL before loading data.
                $qb=$em->getRepository($class)->createQueryBuilder('e')->where('e.verwijderdOp IS NOT NULL')->orderBy('e.verwijderdOp','DESC')->setMaxResults(100);
                if (is_a($class,Tikking::class,true)) { $qb->join('e.lijst','parent')->andWhere('parent.afdeling = :afd'); }
                elseif ($class === \App\Entity\Foutbericht::class) { $qb->join('e.foutmelding','parent')->andWhere('parent.afdeling = :afd'); }
                else { $qb->andWhere('e.afdeling = :afd'); }
                $qb->setParameter('afd',$this->afdeling());
                foreach ($qb->getQuery()->getResult() as $entity) {
                    $label=$entity instanceof Tikking ? $entity->getPersoon()->getVolledigeNaam().' · lijst '.$entity->getLijst()->getLabel() : (method_exists($entity,'getNaam') ? $entity->getNaam() : (method_exists($entity,'getWat') ? $entity->getWat() : '#'.$entity->getId()));
                    $items[]=['type'=>$key,'entity'=>$entity,'label'=>$label];
                }
            }
        } finally { $em->getFilters()->enable('soft_delete'); }
        return $this->render('historiek/prullenbak.html.twig',['items'=>$items]);
    }
    #[Route('/herstellen/{type}/{id}', name: 'app_herstellen', requirements: ['id'=>'\d+'], methods: ['POST'])]
    public function herstellen(string $type,int $id,Request $request,EntityManagerInterface $em,AuditLog $log,SpecialeTikService $speciaal): Response
    {
        if (!isset(self::TYPES[$type]) || !$this->csrfOk($request,'herstel'.$type.$id)) { throw $this->createAccessDeniedException(); }
        $em->getFilters()->disable('soft_delete');
        try {
            $entity=$em->find(self::TYPES[$type],$id);
            $afdelingId=$entity ? $log->afdeling($entity) : null;
            if ($entity instanceof \App\Entity\Foutbericht) { $em->initializeObject($entity->getFoutmelding()); }
        }
        finally { $em->getFilters()->enable('soft_delete'); }
        if (!$entity || !$entity->isVerwijderd() || $afdelingId !== $this->afdeling()->getId()) { throw $this->createNotFoundException(); }
        try {
            if ($entity instanceof \App\Entity\Foutbericht && $entity->getFoutmelding()->isVerwijderd()) { throw new TikFout('Herstel eerst de bijbehorende foutmelding.'); }
            $em->wrapInTransaction(function () use ($entity,$em,$speciaal): void {
                if ($entity instanceof Tikking) {
                    $lijst=$entity->getLijst();
                    $em->refresh($lijst, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    if ($lijst->isVerwijderd() || $lijst->isAfgesloten()) { throw new TikFout('Heropen eerst de lijst om deze tikking te herstellen.'); }
                    if ($lijst->isSpeciaal()) { $speciaal->controleerOverlap($lijst,$entity->getPersoon(),$entity->getStart(),$entity->getEinde(),$entity); }
                    elseif ($lijst->heeftGetikt($entity->getPersoon())) { throw new TikFout('Deze persoon heeft al een actieve tikking op de lijst.'); }
                }
                $entity->herstel();
            });
            $this->addFlash('success','Gegevens hersteld.');
        } catch (TikFout $e) { $this->addFlash('warning',$e->getMessage()); }
        return $this->redirectToRoute('app_prullenbak');
    }
}

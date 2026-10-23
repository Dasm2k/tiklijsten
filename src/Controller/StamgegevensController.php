<?php

namespace App\Controller;

use App\Entity\Gemeente;
use App\Entity\Herinnering;
use App\Entity\InterventieType;
use App\Entity\Materiaal;
use App\Entity\Voertuig;
use App\Form\GemeenteType;
use App\Form\HerinneringType;
use App\Form\InterventieTypeType;
use App\Form\MateriaalType;
use App\Form\VoertuigType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Eenvoudige lijsten die vroeger enkel via phpMyAdmin aangepast konden worden,
 * plus voertuigen en materiaal.
 */
#[Route('/beheer/{soort}', requirements: ['soort' => 'gemeenten|interventies|herinneringen|voertuigen|materiaal'])]
#[IsGranted('ROLE_BEHEER')]
final class StamgegevensController extends BaseController
{
    /** @var array<string, array{class: class-string, form: class-string, titel: string, enkelvoud: string, kolommen: array<string, string>, verwijderen: bool, sort: array<string, string>}> */
    private const SOORTEN = [
        'gemeenten' => ['class' => Gemeente::class, 'form' => GemeenteType::class, 'titel' => 'Gemeenten', 'enkelvoud' => 'gemeente',
            'kolommen' => ['naam' => 'Naam', 'actief' => 'Actief'], 'verwijderen' => false, 'sort' => ['naam' => 'ASC']],
        'interventies' => ['class' => InterventieType::class, 'form' => InterventieTypeType::class, 'titel' => 'Soorten interventie', 'enkelvoud' => 'soort interventie',
            'kolommen' => ['volgorde' => 'Volgorde', 'naam' => 'Naam', 'actief' => 'Actief', 'inStatistiek' => 'In jaarstatistiek', 'chauffeurVerplicht' => 'Chauffeur verplicht', 'interventienummerVerplicht' => 'Interventienummer verplicht'], 'verwijderen' => false, 'sort' => ['volgorde' => 'ASC', 'naam' => 'ASC']],
        'herinneringen' => ['class' => Herinnering::class, 'form' => HerinneringType::class, 'titel' => 'Controlevragen op de afdruk', 'enkelvoud' => 'controlevraag',
            'kolommen' => ['vraag' => 'Vraag'], 'verwijderen' => true, 'sort' => ['id' => 'ASC']],
        'voertuigen' => ['class' => Voertuig::class, 'form' => VoertuigType::class, 'titel' => 'Voertuigen', 'enkelvoud' => 'voertuig',
            'kolommen' => ['naam' => 'Naam', 'post' => 'Post', 'actief' => 'Actief', 'roepnaam' => 'Roepnaam', 'nummerplaat' => 'Nummerplaat', 'keuring' => 'Keuring'], 'verwijderen' => false, 'sort' => ['naam' => 'ASC']],
        'materiaal' => ['class' => Materiaal::class, 'form' => MateriaalType::class, 'titel' => 'Materiaal', 'enkelvoud' => 'materiaal',
            'kolommen' => ['naam' => 'Naam', 'keuring' => 'Keuring'], 'verwijderen' => true, 'sort' => ['naam' => 'ASC']],
    ];

    #[Route('', name: 'app_stam_index', methods: ['GET'])]
    public function index(string $soort, EntityManagerInterface $em): Response
    {
        $cfg = $this->config($soort);
        return $this->render('stam/index.html.twig', [
            'soort' => $soort,
            'cfg' => $cfg,
            'items' => $em->getRepository($cfg['class'])->findBy(['afdeling' => $this->afdeling()], $cfg['sort']),
        ]);
    }

    #[Route('/nieuw', name: 'app_stam_nieuw', methods: ['GET', 'POST'])]
    #[Route('/{id}', name: 'app_stam_bewerken', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function form(string $soort, Request $request, EntityManagerInterface $em, ?int $id = null): Response
    {
        $cfg = $this->config($soort);
        $item = $id ? $this->eigen($em->find($cfg['class'], $id)) : (new $cfg['class']())->setAfdeling($this->afdeling());
        $form = $this->createForm($cfg['form'], $item);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($item);
            $em->flush();
            $this->addFlash('success', ucfirst($cfg['enkelvoud']).' opgeslagen.');
            return $this->redirectToRoute('app_stam_index', ['soort' => $soort]);
        }
        return $this->render('stam/form.html.twig', ['soort' => $soort, 'cfg' => $cfg, 'form' => $form, 'item' => $item], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/verwijderen', name: 'app_stam_verwijderen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verwijderen(string $soort, int $id, Request $request, EntityManagerInterface $em): Response
    {
        $cfg = $this->config($soort);
        $item = $this->eigen($em->find($cfg['class'], $id));
        if ($cfg['verwijderen'] && $this->csrfOk($request, 'weg'.$soort.$id)) {
            $em->remove($item);
            $em->flush();
            $this->addFlash('success', ucfirst($cfg['enkelvoud']).' verwijderd.');
        }
        return $this->redirectToRoute('app_stam_index', ['soort' => $soort]);
    }

    private function config(string $soort): array
    {
        if (\in_array($soort, ['voertuigen', 'materiaal'], true) && !$this->afdeling()->isVoertuigenActief()) {
            throw $this->createNotFoundException();
        }
        return self::SOORTEN[$soort];
    }
}

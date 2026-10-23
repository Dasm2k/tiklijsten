<?php

namespace App\Controller;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Alles gebeurt binnen de afdeling van de aangemelde persoon. */
abstract class BaseController extends AbstractController
{
    public function __construct(private readonly \App\Service\AfdelingContext $afdelingContext) {}

    protected function persoon(): Persoon
    {
        $persoon = $this->getUser();
        if (!$persoon instanceof Persoon) {
            throw $this->createAccessDeniedException();
        }
        return $persoon;
    }

    protected function afdeling(): Afdeling
    {
        return $this->afdelingContext->huidige() ?? throw $this->createAccessDeniedException();
    }

    /** Controleert dat een object tot de afdeling van de gebruiker behoort. */
    protected function eigen(?object $object): object
    {
        if (null === $object || !method_exists($object, 'getAfdeling') || $object->getAfdeling()?->getId() !== $this->afdeling()->getId()) {
            throw new NotFoundHttpException();
        }
        return $object;
    }

    protected function csrfOk(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, (string) $request->request->get('_token'));
    }

    protected function pagina(Request $request): int
    {
        return max(1, $request->query->getInt('p', 1));
    }
}

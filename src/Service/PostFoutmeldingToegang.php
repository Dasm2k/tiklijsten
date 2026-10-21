<?php

namespace App\Service;

use App\Entity\Persoon;
use App\Repository\PersoonRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Persoonlijke meldtoegang binnen de bestaande sessie van één postaccount. */
final class PostFoutmeldingToegang
{
    private const SLEUTEL = 'post_foutmelding_toegang';
    private const GELDIGHEID = 900;

    public function __construct(private readonly PersoonRepository $personen)
    {
    }

    public function aanmelden(SessionInterface $sessie, Persoon $post, Persoon $melder): void
    {
        if (!$post->isTikPc() || !$post->isActief() || !$post->getId() || !$post->getAfdeling()?->getId()
            || !$melder->getId() || !$melder->isActief() || $melder->isSysteemaccount() || $melder->isTikPc()
            || $melder->getAfdeling()?->getId() !== $post->getAfdeling()->getId()) {
            throw new \LogicException('Deze persoon kan niet aanmelden voor een melding vanuit dit postaccount.');
        }

        $sessie->set(self::SLEUTEL, [
            'post' => $post->getId(),
            'afdeling' => $post->getAfdeling()->getId(),
            'melder' => $melder->getId(),
            'code' => hash('sha256', $melder->getCode()),
            'tot' => time() + self::GELDIGHEID,
        ]);
    }

    public function melder(SessionInterface $sessie, Persoon $post): ?Persoon
    {
        $toegang = $sessie->get(self::SLEUTEL);
        if (!$post->isTikPc() || !$post->isActief() || !$post->getId() || !is_array($toegang)
            || ($toegang['post'] ?? null) !== $post->getId()
            || ($toegang['afdeling'] ?? null) !== $post->getAfdeling()?->getId()
            || !is_int($toegang['tot'] ?? null) || $toegang['tot'] <= time()
            || !is_int($toegang['melder'] ?? null) || !is_string($toegang['code'] ?? null)) {
            $this->afmelden($sessie);

            return null;
        }

        $melder = $this->personen->find($toegang['melder']);
        if (!$melder instanceof Persoon || !$melder->isActief() || $melder->isSysteemaccount() || $melder->isTikPc()
            || $melder->getAfdeling()?->getId() !== $post->getAfdeling()?->getId()
            || !hash_equals($toegang['code'], hash('sha256', $melder->getCode()))) {
            $this->afmelden($sessie);

            return null;
        }

        return $melder;
    }

    public function afmelden(SessionInterface $sessie): void
    {
        $sessie->remove(self::SLEUTEL);
    }
}

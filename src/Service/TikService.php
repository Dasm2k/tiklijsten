<?php

namespace App\Service;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Afdeling;
use App\Entity\Persoon;
use App\Entity\Tikking;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Intikken op de tik-pc met tiknummer + code. */
final class TikService
{
    public function __construct(
        private readonly PersoonRepository $personen,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        #[Target('tikken')]
        private readonly RateLimiterFactoryInterface $tikkenLimiter,
    ) {
    }

    /**
     * Controleert tiknummer + code binnen de afdeling van de lijst.
     *
     * @throws TikFout
     */
    public function controleer(Aanwezigheidslijst $lijst, string $tiknummer, string $code): Persoon
    {
        return $this->controleerPersoon($lijst->getAfdeling(), $tiknummer, $code);
    }

    /** Persoonlijk aanmelden voor een foutmelding op een gedeelde tik-pc. @throws TikFout */
    public function controleerVoorFoutmelding(Afdeling $afdeling, string $tiknummer, string $code): Persoon
    {
        return $this->controleerPersoon($afdeling, $tiknummer, $code, true);
    }

    private function controleerPersoon(Afdeling $afdeling, string $tiknummer, string $code, bool $actiefVereist = false): Persoon
    {
        $tiknummer = trim($tiknummer);
        if (!ctype_digit($tiknummer) || '' === $code) {
            throw new TikFout('Geef je tiknummer en code in.');
        }
        $persoon = $this->personen->findOneInAfdeling($afdeling, (int) $tiknummer);
        if (!$persoon || $persoon->isSysteemaccount() || ($actiefVereist && $persoon->isTikPc())) {
            throw new TikFout('Tiknummer bestaat niet.');
        }
        if ($actiefVereist && !$persoon->isActief()) {
            throw new TikFout('Dit account is niet actief. Neem contact op met de beheerder.');
        }
        $this->controleerCode($persoon, $code, 'Code niet juist.');
        if ($this->hasher->needsRehash($persoon)) {
            $persoon->setCode($this->hasher->hashPassword($persoon, $code));
        }
        return $persoon;
    }

    /** @throws TikFout */
    public function tikIn(Aanwezigheidslijst $lijst, string $tiknummer, string $code, bool $tweedeStart = false): Tikking
    {
        if ($lijst->isAfgesloten()) {
            throw new TikFout('Deze lijst is reeds afgesloten.');
        }
        $persoon = $this->controleer($lijst, $tiknummer, $code);
        if ($lijst->heeftGetikt($persoon)) {
            throw new TikFout($persoon->getVolledigeNaam().' is reeds geregistreerd voor deze interventie.');
        }

        $nu = new \DateTimeImmutable();
        $tikking = (new Tikking())->setPersoon($persoon)->setEinde($nu);
        if ($tweedeStart && $lijst->getTweedeStart()) {
            $tikking->setStart($lijst->getTweedeStart());
        }
        $lijst->addTikking($tikking);

        // Zoals in de oude toepassing (post Boom): wie tikt is opnieuw actief.
        $persoon->setActief(true)->setLaatstGezien($nu);

        $this->em->persist($tikking);
        $this->em->flush();

        return $tikking;
    }

    /** Een tikking verwijderen kan enkel met de code van de betrokkene. @throws TikFout */
    public function verwijder(Aanwezigheidslijst $lijst, Tikking $tikking, string $code): void
    {
        if ($lijst->isAfgesloten()) {
            throw new TikFout('Deze lijst is reeds afgesloten.');
        }
        $this->controleerCode($tikking->getPersoon(), $code, 'Verwijderen mislukt: code niet juist.');
        $lijst->removeTikking($tikking);
        $this->em->flush();
    }

    /** Beperkt het aantal foute codes per persoon (4 cijfers zijn anders snel geraden). @throws TikFout */
    private function controleerCode(Persoon $persoon, string $code, string $melding): void
    {
        $limiter = $this->tikkenLimiter->create($persoon->getUserIdentifier());
        if ($limiter->consume(0)->getRemainingTokens() <= 0) {
            throw new TikFout('Te veel foute codes voor dit tiknummer. Probeer over 15 minuten opnieuw.');
        }
        if (!$this->hasher->isPasswordValid($persoon, $code)) {
            $limiter->consume(1);
            throw new TikFout($melding);
        }
        $limiter->reset();
    }
}

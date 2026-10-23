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
        private readonly AuditContext $audit,
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

    /** Persoonlijke bevestiging op de kiosk; superadmin blijft achter de 2FA-login. */
    public function controleerUitvoerder(Afdeling $afdeling, string $tiknummer, string $code): Persoon
    {
        $tiknummer = trim($tiknummer);
        $persoon = ctype_digit($tiknummer) ? $this->personen->findOneInAfdeling($afdeling, (int) $tiknummer) : null;
        if (!$persoon || !$persoon->isActief() || $persoon->isSuperadmin()) { throw new TikFout('Gebruik een actief persoonlijk account. Superadmin meldt aan via 2FA.'); }
        $this->controleerCode($persoon,$code,'Code niet juist.');
        return $persoon;
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
        if ($lijst->isSpeciaal()) { throw new TikFout('Kies Begin of Einde op de speciale lijst.'); }
        $persoon = $this->controleer($lijst, $tiknummer, $code);
        $this->audit->namens($persoon);
        return $this->em->wrapInTransaction(function () use ($lijst, $persoon, $tweedeStart): Tikking {
        $this->em->refresh($lijst, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        if ($lijst->isAfgesloten()) { throw new TikFout('Deze lijst is reeds afgesloten.'); }
        if ($lijst->heeftGetikt($persoon)) {
            throw new TikFout($persoon->getVolledigeNaam().' is reeds geregistreerd voor deze interventie.');
        }

        $nu = new \DateTimeImmutable();
        $nummer = 1 + (int) $this->em->getConnection()->fetchOne('SELECT MAX(volgnummer) FROM tikking WHERE lijst_id = ? AND persoon_id = ?', [$lijst->getId(), $persoon->getId()]);
        $tikking = (new Tikking())->setVolgnummer($nummer)->setPersoon($persoon)->setEinde($nu);
        if ($tweedeStart && $lijst->getTweedeStart()) {
            $tikking->setStart($lijst->getTweedeStart());
        }
        $lijst->addTikking($tikking);

        // Zoals in de oude toepassing (post Boom): wie tikt is opnieuw actief.
        $persoon->setActief(true)->setLaatstGezien($nu);

        $this->em->persist($tikking);
        $this->em->flush();

        return $tikking;
        });
    }

    /** Een tikking verwijderen kan enkel met de code van de betrokkene. @throws TikFout */
    public function verwijder(Aanwezigheidslijst $lijst, Tikking $tikking, string $code): void
    {
        $this->controleerCode($tikking->getPersoon(), $code, 'Verwijderen mislukt: code niet juist.');
        $this->audit->namens($tikking->getPersoon());
        $this->em->wrapInTransaction(function () use ($lijst,$tikking): void {
            $this->em->refresh($lijst, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ($lijst->isAfgesloten()) { throw new TikFout('Deze lijst is reeds afgesloten.'); }
            $tikking->markeerVerwijderd($tikking->getPersoon()->getId());
        });
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

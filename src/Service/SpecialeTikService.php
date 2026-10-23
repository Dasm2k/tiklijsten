<?php
namespace App\Service;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Persoon;
use App\Entity\Tikking;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class SpecialeTikService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuditContext $audit, private readonly SpecialeLijstRechten $rechten) {}

    public static function tijd(string $waarde): \DateTimeImmutable
    {
        $tijd = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $waarde);
        if (!$tijd || $tijd->format('Y-m-d\TH:i') !== $waarde) { throw new TikFout('Geef een geldige datum en tijd in.'); }
        return $tijd;
    }
    public function registreer(Aanwezigheidslijst $lijst, Persoon $persoon, string $actie, \DateTimeImmutable $moment): Tikking
    {
        $this->audit->namens($persoon);
        return $this->em->wrapInTransaction(function () use ($lijst, $persoon, $actie, $moment): Tikking {
            $this->controleerLijst($lijst, $persoon); $this->controleerTijd($lijst, $moment);
            $open = $this->em->getRepository(Tikking::class)->findOneBy(['lijst'=>$lijst, 'persoon'=>$persoon, 'einde'=>null]);
            if ('begin' === $actie) {
                if ($open) { throw new TikFout('Je hebt nog een open registratie. Registreer eerst je einde.'); }
                $this->controleerOverlap($lijst, $persoon, $moment, null);
                $volgnummer = 1 + (int) $this->em->getConnection()->fetchOne('SELECT MAX(volgnummer) FROM tikking WHERE lijst_id = ? AND persoon_id = ?', [$lijst->getId(), $persoon->getId()]);
                $tikking = (new Tikking())->setPersoon($persoon)->setStart($moment)->setEinde(null)->setVolgnummer($volgnummer);
                $lijst->addTikking($tikking); $this->em->persist($tikking);
            } elseif ('einde' === $actie) {
                if (!$open) { throw new TikFout('Je hebt geen open beginregistratie op deze lijst.'); }
                if ($moment <= $open->getStart()) { throw new TikFout('Het einde moet na het begin liggen.'); }
                $this->controleerOverlap($lijst, $persoon, $open->getStart(), $moment, $open);
                $open->setEinde($moment); $tikking = $open;
            } else { throw new TikFout('Kies Begin of Einde.'); }
            $persoon->setLaatstGezien(new \DateTimeImmutable());
            return $tikking;
        });
    }
    public function wijzig(Tikking $tikking, Persoon $persoon, \DateTimeImmutable $begin, ?\DateTimeImmutable $einde): void
    {
        if (($tikking->getPersoon()?->getId() !== $persoon->getId() && !$this->rechten->magAfsluiten($tikking->getLijst(),$persoon)) || $tikking->isVerwijderd()) { throw new TikFout('Je mag deze registratie niet aanpassen.'); }
        $this->audit->namens($persoon);
        $this->em->wrapInTransaction(function () use ($tikking,$persoon,$begin,$einde): void {
            $lijst = $tikking->getLijst(); $this->controleerLijst($lijst, $tikking->getPersoon(), false);
            $this->em->refresh($tikking);
            if ($tikking->isVerwijderd()) { throw new TikFout('Deze registratie is verwijderd.'); }
            $this->controleerTijd($lijst,$begin);
            if ($einde) { $this->controleerTijd($lijst,$einde); if ($einde <= $begin) { throw new TikFout('Het einde moet na het begin liggen.'); } }
            $this->controleerOverlap($lijst,$tikking->getPersoon(),$begin,$einde,$tikking);
            $tikking->setStart($begin)->setEinde($einde);
        });
    }
    private function controleerLijst(Aanwezigheidslijst $lijst, Persoon $persoon, bool $registreren = true): void
    {
        $this->em->refresh($lijst, LockMode::PESSIMISTIC_WRITE);
        if (!$lijst->isSpeciaal() || $lijst->isAfgesloten() || $lijst->isVerwijderd()) { throw new TikFout('Deze speciale lijst is niet open.'); }
        if (($registreren && !$persoon->isActief()) || $persoon->isSysteemaccount() || $persoon->isVerwijderd() || $persoon->getAfdeling()?->getId() !== $lijst->getAfdeling()?->getId()) { throw new TikFout('Dit personeelslid kan niet registreren op deze lijst.'); }
    }
    private function controleerTijd(Aanwezigheidslijst $lijst, \DateTimeImmutable $tijd): void
    {
        if ($tijd < $lijst->getStart() || $tijd > new \DateTimeImmutable('+5 minutes')) { throw new TikFout('De tijd moet vanaf het begin van de interventie liggen en mag niet in de toekomst zijn.'); }
    }
    public function controleerOverlap(Aanwezigheidslijst $lijst, Persoon $persoon, \DateTimeImmutable $begin, ?\DateTimeImmutable $einde, ?Tikking $eigen = null): void
    {
        foreach ($this->em->getRepository(Tikking::class)->findBy(['lijst'=>$lijst,'persoon'=>$persoon]) as $andere) {
            if ($eigen && $andere->getId() === $eigen->getId()) { continue; }
            if ((!$einde || $andere->getStart() < $einde) && (!$andere->getEinde() || $begin < $andere->getEinde())) { throw new TikFout('Deze periode overlapt een andere registratie op deze lijst.'); }
        }
    }
}

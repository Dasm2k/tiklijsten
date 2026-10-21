<?php

namespace App\Service;

use App\Entity\Foutbericht;
use App\Entity\Foutmelding;
use App\Entity\Persoon;
use App\Enum\FoutStatus;
use App\Repository\MailinglijstRepository;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Werkstroom van foutmeldingen. De mailregels volgen de oude toepassing:
 *  - nieuw: mailinglijst "foutmeldingen"
 *  - opnemen: bevestiging aan wie opneemt; toewijzen: aan de nieuwe opvolger
 *  - extra info: melder <-> opvolger (of de foutmeldingen-lijst als er nog geen opvolger is)
 *  - buitendienst: melder + onderofficieren en hoger, of iedereen
 *  - definitief buitendienst: iedereen
 *  - afgewerkt: melder, of iedereen als de melding ooit naar iedereen ging
 */
final class FoutmeldingService
{
    /** Rangniveau vanaf sergeant (onderofficier). */
    private const NIVEAU_ONDEROFFICIER = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Mailer $mailer,
        private readonly MailinglijstRepository $mailinglijsten,
        private readonly PersoonRepository $personen,
    ) {
    }

    public function nieuw(Foutmelding $fout): void
    {
        $this->em->persist($fout);
        $this->em->flush();
        $this->mail($fout, $this->mailinglijsten->ontvangersFoutmeldingen($fout->getAfdeling()), 'Nieuwe foutmelding voor '.$fout->getWat(), 'Er is een nieuwe foutmelding gemaakt.');
    }

    public function opnemen(Foutmelding $fout, Persoon $door): void
    {
        $this->koppelOpvolger($fout, $door, $door->getVoornaam().' '.$door->getNaam().' heeft deze foutmelding opgenomen.', $door);
        $this->mail($fout, [$door->getEmail()], 'Foutmelding voor '.$fout->getWat(), 'Je hebt deze foutmelding opgenomen.');
    }

    public function toewijzen(Foutmelding $fout, Persoon $opvolger, Persoon $door): void
    {
        $this->koppelOpvolger($fout, $opvolger, sprintf('%s %s heeft deze foutmelding toegewezen aan %s %s.', $door->getVoornaam(), $door->getNaam(), $opvolger->getVoornaam(), $opvolger->getNaam()), $door);
        $this->mail($fout, [$opvolger->getEmail()], 'Foutmelding voor '.$fout->getWat(), 'Deze foutmelding werd aan jou toegewezen.');
    }

    private function koppelOpvolger(Foutmelding $fout, Persoon $opvolger, string $tekst, Persoon $door): void
    {
        $fout->setOpvolger($opvolger);
        if ($fout->getStatus()->value <= FoutStatus::TOEGEWEZEN->value) {
            $fout->setStatus(FoutStatus::TOEGEWEZEN);
        }
        $this->bericht($fout, $door, $tekst, $fout->getStatus());
        $this->em->flush();
    }

    public function extraInfo(Foutmelding $fout, Persoon $door, string $tekst): void
    {
        $fout->setStatus(FoutStatus::EXTRA_INFO)->setAfgesloten(null);
        $this->bericht($fout, $door, $tekst, FoutStatus::EXTRA_INFO);
        $this->em->flush();

        $melder = $fout->getMelder();
        $opvolger = $fout->getOpvolger();
        if ($opvolger && $door->getId() === $opvolger->getId()) {
            $aan = [$melder?->getEmail()];
        } elseif ($melder && $door->getId() === $melder->getId()) {
            $aan = $opvolger ? [$opvolger->getEmail()] : $this->mailinglijsten->ontvangersFoutmeldingen($fout->getAfdeling());
        } else {
            $aan = $opvolger
                ? [$melder?->getEmail(), $opvolger->getEmail()]
                : [...$this->mailinglijsten->ontvangersFoutmeldingen($fout->getAfdeling()), $melder?->getEmail()];
        }
        $this->mail($fout, array_filter($aan), 'Foutmelding voor '.$fout->getWat(), 'Er werd extra informatie toegevoegd aan een foutmelding.');
    }

    public function buitendienst(Foutmelding $fout, Persoon $door, string $tekst, bool $iedereen): void
    {
        $fout->setStatus(FoutStatus::BUITENDIENST)->setAfgesloten(null);
        if ($iedereen) {
            $fout->setIedereenMailen(true);
        }
        $this->bericht($fout, $door, $tekst, FoutStatus::BUITENDIENST);
        $this->em->flush();

        $aan = $iedereen
            ? $this->personen->alleEmailadressen($fout->getAfdeling())
            : [$fout->getMelder()?->getEmail(), ...$this->personen->alleEmailadressen($fout->getAfdeling(), self::NIVEAU_ONDEROFFICIER)];
        $this->mail($fout, array_filter($aan), 'Foutmelding voor '.$fout->getWat(), 'Voertuig of toestel buitendienst.');
    }

    public function definitiefBuitendienst(Foutmelding $fout, Persoon $door, string $tekst): void
    {
        $fout->setStatus(FoutStatus::DEFINITIEF_BUITENDIENST)->setAfgesloten(new \DateTimeImmutable());
        $this->bericht($fout, $door, $tekst, FoutStatus::DEFINITIEF_BUITENDIENST);
        $this->em->flush();
        $this->mail($fout, $this->personen->alleEmailadressen($fout->getAfdeling()), 'Foutmelding voor '.$fout->getWat(), 'Voertuig of toestel definitief buitendienst.');
    }

    public function afwerken(Foutmelding $fout, Persoon $door, string $tekst): void
    {
        $fout->setStatus(FoutStatus::AFGEWERKT)->setAfgesloten(new \DateTimeImmutable());
        $this->bericht($fout, $door, '' !== trim($tekst) ? $tekst : 'Afgewerkt.', FoutStatus::AFGEWERKT);
        $this->em->flush();
        $aan = $fout->isIedereenMailen()
            ? $this->personen->alleEmailadressen($fout->getAfdeling())
            : [$fout->getMelder()?->getEmail()];
        $this->mail($fout, array_filter($aan), 'Foutmelding voor '.$fout->getWat(), 'De foutmelding is afgewerkt.');
    }

    /** Terug naar "nieuw": opvolger wordt gewist (geen mail, zoals vroeger). */
    public function terugzetten(Foutmelding $fout, Persoon $door): void
    {
        $fout->setStatus(FoutStatus::NIEUW)->setOpvolger(null)->setAfgesloten(null);
        $this->bericht($fout, $door, $door->getVoornaam().' '.$door->getNaam().' heeft deze foutmelding teruggezet.', FoutStatus::NIEUW);
        $this->em->flush();
    }

    private function bericht(Foutmelding $fout, Persoon $door, string $tekst, FoutStatus $status): void
    {
        $bericht = (new Foutbericht())->setAuteur($door)->setBericht($tekst)->setStatus($status);
        $fout->addBericht($bericht);
        $this->em->persist($bericht);
    }

    /** @param array<string|null> $aan */
    private function mail(Foutmelding $fout, array $aan, string $onderwerp, string $inleiding): void
    {
        $this->mailer->stuur($fout->getAfdeling(), array_filter($aan), $onderwerp, 'emails/foutmelding.html.twig', [
            'fout' => $fout,
            'inleiding' => $inleiding,
        ]);
    }
}

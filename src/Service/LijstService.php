<?php

namespace App\Service;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Persoon;
use App\Repository\AanwezigheidslijstRepository;
use App\Repository\MailinglijstRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LijstService
{
    public function __construct(
        private readonly AanwezigheidslijstRepository $lijsten,
        private readonly MailinglijstRepository $mailinglijsten,
        private readonly EntityManagerInterface $em,
        private readonly Mailer $mailer,
        private readonly ChauffeurConfiguratie $chauffeurConfiguratie,
        private readonly SpecialeLijstRechten $rechten,
        private readonly AuditContext $audit,
    ) {
    }

    /** Nieuwe lijst klaarzetten voor een tik-pc (post + eventueel vast peloton). */
    public function nieuw(Persoon $aanmaker): Aanwezigheidslijst
    {
        $afdeling = $aanmaker->getAfdeling();
        $lijst = (new Aanwezigheidslijst())
            ->setAfdeling($afdeling)
            ->setPost($aanmaker->getPost());
        if ($aanmaker->getVastPeloton()) {
            $lijst->setPeloton($aanmaker->getVastPeloton());
        } else {
            $lijst->setPeloton(0);
        }
        return $lijst;
    }

    public function opslaanNieuw(Aanwezigheidslijst $lijst): void
    {
        $afdeling = $lijst->getAfdeling();
        $lijst->setNummer($this->lijsten->volgendNummer($afdeling));
        $lijst->setVolgnummer($this->lijsten->volgendVolgnummer($afdeling, $lijst->getPeloton()));
        $this->em->persist($lijst);
        $this->em->flush();
    }

    /** Lijst afsluiten en de aanwezigheden mailen naar de mailinglijst(en) van het peloton. */
    public function sluitAf(Aanwezigheidslijst $lijst, ?Persoon $door = null): int
    {
        if ($door) { $this->audit->namens($door); }
        $gesloten = $this->em->wrapInTransaction(function () use ($lijst, $door): bool {
            $this->em->refresh($lijst, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ($lijst->isAfgesloten()) { return false; }
            $this->controleerAfsluiten($lijst, $door);
            $this->vereisChauffeur($lijst);
            $lijst->setAfgesloten(true);
            return true;
        });
        if (!$gesloten) { return 0; }

        $ontvangers = $this->mailinglijsten->ontvangersAfgeslotenLijst($lijst->getAfdeling(), $lijst->getPeloton());
        return $this->mailer->stuur(
            $lijst->getAfdeling(),
            $ontvangers,
            sprintf('Aanwezigheidslijst %s - %de peloton %s', $lijst->getLabel(), $lijst->getPeloton(), $lijst->getStart()->format('d/m/Y - H:i')),
            'emails/lijst_afgesloten.html.twig',
            ['lijst' => $lijst, 'tikkingen' => self::gesorteerdeTikkingen($lijst)],
        );
    }

    public function controleerAfsluiten(Aanwezigheidslijst $lijst, ?Persoon $door = null): void
    {
        if ($lijst->isInterventienummerVerplicht() && null === $lijst->getInterventienummer()) {
            throw new TikFout('Vul eerst het verplichte interventienummer in via Lijst bewerken.');
        }
        if (!$lijst->isSpeciaal()) { return; }
        if (!$this->rechten->magAfsluiten($lijst, $door ?? $this->audit->actor())) { throw new TikFout('Een speciale lijst kan alleen afgesloten worden door een beheerder, sergeant, adjudant, luitenant, kapitein of majoor.'); }
        $open = (int) $this->em->createQuery('SELECT COUNT(t.id) FROM App\\Entity\\Tikking t WHERE t.lijst = :lijst AND t.einde IS NULL')->setParameter('lijst',$lijst)->getSingleScalarResult();
        if ($open) { throw new TikFout('Er zijn nog '.$open.' open beginregistraties. Registreer eerst alle einduren.'); }
    }

    public function vereisChauffeur(Aanwezigheidslijst $lijst): void
    {
        if (!$this->chauffeurConfiguratie->isVerplicht($lijst)) { return; }
        $aantal = (int) $this->em->createQuery('SELECT COUNT(t.id) FROM App\\Entity\\Tikking t WHERE t.lijst = :lijst AND t.chauffeurVoertuig IS NOT NULL')
            ->setParameter('lijst', $lijst)->getSingleScalarResult();
        if (0 === $aantal) {
            throw new TikFout('Duid eerst minstens één chauffeur aan door op de naam van een aanwezige te klikken en een voertuig te kiezen.');
        }
    }

    /** @return \App\Entity\Tikking[] gesorteerd op naam */
    public static function gesorteerdeTikkingen(Aanwezigheidslijst $lijst): array
    {
        $tikkingen = $lijst->getTikkingen()->toArray();
        usort($tikkingen, static fn ($a, $b) => strcasecmp($a->getPersoon()->getVolledigeNaam(), $b->getPersoon()->getVolledigeNaam()));
        return $tikkingen;
    }
}

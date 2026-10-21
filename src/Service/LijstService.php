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
    ) {
    }

    /** Nieuwe lijst klaarzetten voor een tik-pc (post + eventueel vast peloton). */
    public function nieuw(Persoon $aanmaker): Aanwezigheidslijst
    {
        $afdeling = $aanmaker->getAfdeling();
        $lijst = (new Aanwezigheidslijst())
            ->setAfdeling($afdeling)
            ->setPost($aanmaker->getPost())
            ->setStart((new \DateTimeImmutable())->setTime((int) date('H'), (int) date('i')));
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
    public function sluitAf(Aanwezigheidslijst $lijst): int
    {
        $lijst->setAfgesloten(true);
        $this->em->flush();

        $ontvangers = $this->mailinglijsten->ontvangersAfgeslotenLijst($lijst->getAfdeling(), $lijst->getPeloton());
        return $this->mailer->stuur(
            $lijst->getAfdeling(),
            $ontvangers,
            sprintf('Aanwezigheidslijst %s - %de peloton %s', $lijst->getLabel(), $lijst->getPeloton(), $lijst->getStart()->format('d/m/Y - H:i')),
            'emails/lijst_afgesloten.html.twig',
            ['lijst' => $lijst, 'tikkingen' => self::gesorteerdeTikkingen($lijst)],
        );
    }

    /** @return \App\Entity\Tikking[] gesorteerd op naam */
    public static function gesorteerdeTikkingen(Aanwezigheidslijst $lijst): array
    {
        $tikkingen = $lijst->getTikkingen()->toArray();
        usort($tikkingen, static fn ($a, $b) => strcasecmp($a->getPersoon()->getVolledigeNaam(), $b->getPersoon()->getVolledigeNaam()));
        return $tikkingen;
    }
}

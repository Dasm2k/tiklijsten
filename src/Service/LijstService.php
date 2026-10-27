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
        // Lock op de afdeling: twee tik-pc's die tegelijk een lijst maken krijgen elk een eigen nummer.
        $this->em->wrapInTransaction(function () use ($lijst, $afdeling): void {
            $this->em->getConnection()->executeQuery('SELECT id FROM afdeling WHERE id = ? FOR UPDATE', [$afdeling->getId()]);
            $lijst->setNummer($this->lijsten->volgendNummer($afdeling));
            $lijst->setVolgnummer($this->lijsten->volgendVolgnummer($afdeling, $lijst->getPeloton()));
            $this->em->persist($lijst);
        });
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
        return $this->verzendAfsluitmail($lijst);
    }

    /** Houdt de mailstatus bij; herverzenden gebeurt expliciet vanuit beheer. */
    public function verzendAfsluitmail(Aanwezigheidslijst $lijst): int
    {
        if (!$lijst->isAfgesloten()) { throw new \LogicException('Een open lijst mag niet worden gemaild.'); }
        $db = $this->em->getConnection();
        $bestaand = $db->fetchAssociative('SELECT id, status FROM afsluitmail WHERE lijst_id = ?', [$lijst->getId()]);
        if ($bestaand && 'verzonden' === $bestaand['status']) { return 0; }
        if (!$bestaand) {
            try {
                $db->insert('afsluitmail', [
                    'lijst_id' => $lijst->getId(), 'afdeling_id' => $lijst->getAfdeling()->getId(),
                    'status' => 'in_afwachting', 'pogingen' => 0,
                    'aangemaakt_op' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // Een andere aanvraag heeft de registratie al aangemaakt.
            }
        }
        // Claim de verzending atomisch: enkel de aanvraag die de rij omzet, verstuurt de mail.
        // Een verzending die langer dan 10 minuten 'bezig' blijft (bv. afgebroken proces) mag opnieuw.
        $nu = new \DateTimeImmutable();
        $geclaimd = $db->executeStatement(
            "UPDATE afsluitmail SET status = 'bezig', bezig_sinds = ?, pogingen = pogingen + 1 WHERE lijst_id = ? AND (status IN ('in_afwachting', 'mislukt', 'geen_ontvangers') OR (status = 'bezig' AND (bezig_sinds IS NULL OR bezig_sinds < ?)))",
            [$nu->format('Y-m-d H:i:s'), $lijst->getId(), $nu->modify('-10 minutes')->format('Y-m-d H:i:s')],
        );
        if (1 !== $geclaimd) { return 0; }
        $ontvangers = $this->mailinglijsten->ontvangersAfgeslotenLijst($lijst->getAfdeling(), $lijst->getPeloton());
        try {
            $aantal = $this->mailer->stuur(
            $lijst->getAfdeling(),
            $ontvangers,
            sprintf('Aanwezigheidslijst %s - %de peloton %s', $lijst->getLabel(), $lijst->getPeloton(), $lijst->getStart()->format('d/m/Y - H:i')),
            'emails/lijst_afgesloten.html.twig',
            ['lijst' => $lijst, 'tikkingen' => self::gesorteerdeTikkingen($lijst)],
            );
            $db->update('afsluitmail', [
                'status' => $aantal > 0 ? 'verzonden' : (count($ontvangers) ? 'mislukt' : 'geen_ontvangers'),
                'ontvangers' => json_encode($ontvangers, JSON_THROW_ON_ERROR),
                'fout' => $aantal > 0 ? null : (count($ontvangers) ? 'Mailer gaf geen succesvolle verzending terug.' : 'Geen ontvangers gevonden.'),
                'verzonden_op' => $aantal > 0 ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            ], ['lijst_id' => $lijst->getId()]);
            return $aantal;
        } catch (\Throwable $e) {
            $db->update('afsluitmail', ['status' => 'mislukt', 'fout' => mb_substr($e->getMessage(), 0, 4000)], ['lijst_id' => $lijst->getId()]);
            return 0;
        }
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

    /** @return \App\Entity\Tikking[] volgens de instellingen van de afdeling */
    public static function gesorteerdeTikkingen(Aanwezigheidslijst $lijst): array
    {
        $tikkingen = $lijst->getTikkingen()->toArray();
        $afdeling = $lijst->getAfdeling();
        $sortering = $afdeling->getTikkingSortering();
        $rangFactor = 'asc' === $afdeling->getRangVolgorde() ? 1 : -1;
        $naamFactor = 'desc' === $afdeling->getNaamVolgorde() ? -1 : 1;
        usort($tikkingen, static function ($a, $b) use ($sortering, $rangFactor, $naamFactor): int {
            $pa = $a->getPersoon(); $pb = $b->getPersoon();
            $rang = (($pa->getRang()?->getNiveau() ?? -1) <=> ($pb->getRang()?->getNiveau() ?? -1)) * $rangFactor;
            $naam = strcasecmp($pa->getNaam(), $pb->getNaam()) ?: strcasecmp($pa->getVoornaam(), $pb->getVoornaam());
            $naam *= $naamFactor;
            $vergelijk = match ($sortering) {
                'naam' => $naam,
                'naam_rang' => $naam ?: $rang,
                default => $rang ?: $naam,
            };
            return $vergelijk ?: (($a->getId() ?? 0) <=> ($b->getId() ?? 0));
        });
        return $tikkingen;
    }
}

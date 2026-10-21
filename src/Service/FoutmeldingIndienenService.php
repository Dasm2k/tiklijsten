<?php

namespace App\Service;

use App\Entity\Foutmelding;
use App\Entity\Persoon;
use App\Enum\FoutType;
use Doctrine\ORM\EntityManagerInterface;

/** Gedeelde verwerking voor persoonlijke meldingen en meldingen vanaf een tik-pc. */
final class FoutmeldingIndienenService
{
    public function __construct(
        private readonly FoutmeldingService $foutmeldingen,
        private readonly Mailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $data Gevalideerde gegevens uit FoutmeldingType. */
    public function indienen(Persoon $melder, array $data): ?Foutmelding
    {
        $afdeling = $melder->getAfdeling();
        if (!$afdeling || !$afdeling->isFoutmeldingenActief()) {
            throw new \LogicException('Foutmeldingen zijn niet actief voor deze afdeling.');
        }

        if (!$melder->getEmail() && !empty($data['email'])) {
            $melder->setEmail($data['email']);
            $this->em->flush();
        }
        /** @var FoutType $type */
        $type = $data['type'];
        $probleem = trim($data['probleem']);

        if (FoutType::KADAVER === $type) {
            $this->mailer->stuur($afdeling, $afdeling->getKadaverOntvangersLijst(), 'Kadaver '.$data['soortKadaver'], 'emails/kadaver.html.twig', [
                'melder' => $melder, 'soort' => $data['soortKadaver'], 'locatie' => $data['locatieKadaver'],
                'opdracht' => $data['inOpdrachtVan'], 'info' => $probleem,
            ]);

            return null;
        }

        $wat = trim((string) $data['wat']);
        if (FoutType::VOERTUIG === $type && $data['voertuig']) {
            $voertuig = $data['voertuig'];
            if ($voertuig->getAfdeling()?->getId() !== $afdeling->getId()) {
                throw new \LogicException('Het voertuig behoort niet tot de afdeling van de melder.');
            }
            $wat = $voertuig->getNaam();
            $probleem .= "\n\n".$voertuig->getNaam().' - nummerplaat: '.($voertuig->getNummerplaat() ?: '?').' - vervaldatum keuring: '.($voertuig->getKeuring()?->format('d/m/Y') ?? '?');
        } elseif (FoutType::KLEDIJ === $type) {
            $wat = sprintf('Kledij: %d stuks - tiknummer %d', \count($data['kledij']), $melder->getTiknummer());
            $probleem .= "\n\n- ".implode("\n- ", $data['kledij']);
        }

        $fout = (new Foutmelding())->setAfdeling($afdeling)->setType($type)->setWat($wat)->setProbleem($probleem)->setMelder($melder);
        $this->foutmeldingen->nieuw($fout);

        return $fout;
    }
}

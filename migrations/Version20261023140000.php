<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023140000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // MySQL en MariaDB voeren bij schemawijzigingen een impliciete COMMIT uit.
        return false;
    }

    public function getDescription(): string
    {
        return 'Afzonderlijke momentopname van de voertuignaam voor de korte chauffeurvermelding.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tikking ADD chauffeur_voertuig_naam VARCHAR(128) DEFAULT NULL');

        // Bestaande samengestelde vermeldingen blijven ongewijzigd bewaard.
        foreach ($this->connection->iterateAssociative('SELECT t.id, t.chauffeur_vermelding, v.naam, v.roepnaam, v.nummerplaat FROM tikking t LEFT JOIN voertuig v ON v.id = t.chauffeur_voertuig_id WHERE t.chauffeur_vermelding IS NOT NULL OR t.chauffeur_voertuig_id IS NOT NULL') as $rij) {
            $naam = $this->voertuigNaam($rij);
            if (null !== $naam && mb_strlen($naam, 'UTF-8') > 128) {
                $naam = $rij['naam'];
            }
            $this->addSql('UPDATE tikking SET chauffeur_voertuig_naam = ? WHERE id = ?', [$naam, $rij['id']]);
        }
    }

    private function voertuigNaam(array $rij): ?string
    {
        $naam = (string) ($rij['naam'] ?? '');
        $roepnaam = (string) ($rij['roepnaam'] ?? '');
        $nummerplaat = (string) ($rij['nummerplaat'] ?? '');
        $vermelding = (string) ($rij['chauffeur_vermelding'] ?? '');
        if ('' === $vermelding || $vermelding === implode(' · ', array_filter([$roepnaam, $naam, $nummerplaat]))) {
            return '' !== $naam ? $naam : null;
        }

        // Behoud ook de oude naam als het voertuig intussen hernoemd is.
        $voor = '' !== $roepnaam && str_starts_with($vermelding, $roepnaam.' · ');
        $achter = '' !== $nummerplaat && str_ends_with($vermelding, ' · '.$nummerplaat);
        if (($voor || '' === $roepnaam) && ($achter || '' === $nummerplaat)) {
            if ($voor) { $vermelding = substr($vermelding, strlen($roepnaam.' · ')); }
            if ($achter) { $vermelding = substr($vermelding, 0, -strlen(' · '.$nummerplaat)); }
            return $vermelding;
        }

        $delen = explode(' · ', $vermelding);
        if (count($delen) >= 3) { return implode(' · ', array_slice($delen, 1, -1)); }
        if (1 === count($delen)) { return $vermelding; }
        if ($voor) { return $delen[1]; }
        if ($achter) { return $delen[0]; }
        if (in_array($naam, $delen, true)) { return $naam; }
        return '' !== $roepnaam ? $delen[1] : $delen[0];
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tikking DROP chauffeur_voertuig_naam');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023133000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // MySQL en MariaDB voeren bij schemawijzigingen een impliciete COMMIT uit.
        return false;
    }

    public function getDescription(): string
    {
        return 'Configureerbare chauffeurverplichting per interventietype; vrijstelling voor oefeningen, vergaderingen, jeugdbrandweer en administratie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE interventie_type ADD chauffeur_verplicht TINYINT NOT NULL DEFAULT 1');
        $this->addSql("UPDATE interventie_type SET chauffeur_verplicht = 0 WHERE LOWER(TRIM(naam)) IN ('oefening', 'oefeningen', 'vergadering', 'vergaderingen', 'jeugdbrandweer', 'administratie')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE interventie_type DROP chauffeur_verplicht');
    }
}

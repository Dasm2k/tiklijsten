<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023180000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // MySQL en MariaDB voeren bij schemawijzigingen een impliciete COMMIT uit.
        return false;
    }

    public function getDescription(): string
    {
        return 'Verwijder de vervallen centralistkoppeling volledig uit aanwezigheidslijsten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AA936AF155, DROP INDEX IDX_BE6772AA936AF155, DROP centralist_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('De vervallen centralistgegevens zijn definitief verwijderd en kunnen niet worden hersteld.');
    }
}

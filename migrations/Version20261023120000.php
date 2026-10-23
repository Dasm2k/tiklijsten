<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // MySQL en MariaDB voeren bij schemawijzigingen een impliciete COMMIT uit.
        return false;
    }

    public function getDescription(): string { return 'Speciale lijsten met meerdere begin/eindperiodes, soft delete en handelingenlog.'; }
    public function up(Schema $schema): void
    {
        foreach (['aanwezigheidslijst','afdeling','chauffeur_instelling','foutbericht','foutmelding','gemeente','herinnering','interventie_type','mailinglijst','materiaal','persoon','post','rang','tikking','voertuig'] as $tabel) {
            $this->addSql('ALTER TABLE '.$tabel.' ADD verwijderd_op DATETIME DEFAULT NULL, ADD verwijderd_door INT DEFAULT NULL');
        }
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD speciaal TINYINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE tikking DROP INDEX uniq_tikking, ADD volgnummer INT NOT NULL DEFAULT 1, MODIFY einde DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_tikking ON tikking (lijst_id, persoon_id, volgnummer)');
        $this->addSql('CREATE TABLE handeling (id INT AUTO_INCREMENT NOT NULL, datum DATETIME NOT NULL, afdeling_id INT DEFAULT NULL, actor_id INT DEFAULT NULL, actor VARCHAR(180) NOT NULL, actie VARCHAR(64) NOT NULL, object_type VARCHAR(160) NOT NULL, object_id INT DEFAULT NULL, route VARCHAR(160) NOT NULL, request_id VARCHAR(64) NOT NULL, voor JSON NOT NULL, na JSON NOT NULL, INDEX idx_handeling_afdeling_datum (afdeling_id, datum), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Bevat aanwezigheidsperiodes en auditgeschiedenis. Herstel via een gecontroleerde back-up.'); }
}

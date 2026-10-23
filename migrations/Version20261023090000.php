<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Chauffeurs met historiek, voertuigpost en actiefstatus, superadmin TOTP.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voertuig ADD post_id INT DEFAULT NULL, ADD actief TINYINT NOT NULL DEFAULT 1');
        $this->addSql('CREATE INDEX IDX_VOERTUIG_POST ON voertuig (post_id)');
        $this->addSql('ALTER TABLE voertuig ADD CONSTRAINT FK_VOERTUIG_POST FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE tikking ADD chauffeur_voertuig_id INT DEFAULT NULL, ADD chauffeur_vermelding VARCHAR(400) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_TIKKING_CHAUFFEUR ON tikking (chauffeur_voertuig_id)');
        $this->addSql('ALTER TABLE tikking ADD CONSTRAINT FK_TIKKING_CHAUFFEUR FOREIGN KEY (chauffeur_voertuig_id) REFERENCES voertuig (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE persoon ADD totp_secret VARCHAR(64) DEFAULT NULL, ADD totp_last_step INT DEFAULT NULL');
        // Vehicle post is deliberately left empty: Boom/Rumst share an afdeling.
        // An administrator assigns each existing vehicle to its actual post.
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Bevat chauffeursregistraties en 2FA-gegevens. Herstel via een gecontroleerde back-up.');
    }
}

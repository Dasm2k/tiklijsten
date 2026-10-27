<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027200000 extends AbstractMigration
{
    public function isTransactional(): bool { return false; }

    public function getDescription(): string
    {
        return 'Claimtijdstip voor afsluitmails en indexnamen gelijkgetrokken met de Doctrine-mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE afsluitmail ADD bezig_sinds DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE persoon_chauffeur_post RENAME INDEX idx_persoon_chauffeur_persoon TO IDX_DEE21CFE90FBB45F');
        $this->addSql('ALTER TABLE persoon_chauffeur_post RENAME INDEX idx_persoon_chauffeur_post TO IDX_DEE21CFE4B89032C');
        $this->addSql('ALTER TABLE tikking RENAME INDEX idx_tikking_chauffeur TO IDX_C7DB01F769C2F317');
        $this->addSql('ALTER TABLE chauffeur_instelling RENAME INDEX idx_chauffeur_afdeling TO IDX_6A5E8921C5BE90E3');
        $this->addSql('ALTER TABLE chauffeur_instelling RENAME INDEX idx_chauffeur_post TO IDX_6A5E89214B89032C');
        $this->addSql('ALTER TABLE voertuig RENAME INDEX idx_voertuig_post TO IDX_7846EF514B89032C');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voertuig RENAME INDEX IDX_7846EF514B89032C TO idx_voertuig_post');
        $this->addSql('ALTER TABLE chauffeur_instelling RENAME INDEX IDX_6A5E89214B89032C TO idx_chauffeur_post');
        $this->addSql('ALTER TABLE chauffeur_instelling RENAME INDEX IDX_6A5E8921C5BE90E3 TO idx_chauffeur_afdeling');
        $this->addSql('ALTER TABLE tikking RENAME INDEX IDX_C7DB01F769C2F317 TO idx_tikking_chauffeur');
        $this->addSql('ALTER TABLE persoon_chauffeur_post RENAME INDEX IDX_DEE21CFE4B89032C TO idx_persoon_chauffeur_post');
        $this->addSql('ALTER TABLE persoon_chauffeur_post RENAME INDEX IDX_DEE21CFE90FBB45F TO idx_persoon_chauffeur_persoon');
        $this->addSql('ALTER TABLE afsluitmail DROP bezig_sinds');
    }
}

<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Configureerbare chauffeursregistratie per afdeling en post; standaard uit voor Jeugdbrandweer.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE afdeling ADD chauffeurs_actief TINYINT NOT NULL DEFAULT 1');
        $this->addSql('CREATE TABLE chauffeur_instelling (id INT AUTO_INCREMENT NOT NULL, afdeling_id INT NOT NULL, post_id INT NOT NULL, actief TINYINT NOT NULL, INDEX IDX_CHAUFFEUR_AFDELING (afdeling_id), INDEX IDX_CHAUFFEUR_POST (post_id), UNIQUE INDEX uniq_chauffeur_instelling (afdeling_id, post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE chauffeur_instelling ADD CONSTRAINT FK_CHAUFFEUR_AFDELING FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chauffeur_instelling ADD CONSTRAINT FK_CHAUFFEUR_POST FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql("UPDATE afdeling SET chauffeurs_actief = 0 WHERE LOWER(slug) LIKE '%jeugd%' OR LOWER(naam) LIKE '%jeugdbrandweer%' OR LOWER(titel) LIKE '%jeugdbrandweer%'");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chauffeur_instelling');
        $this->addSql('ALTER TABLE afdeling DROP chauffeurs_actief');
    }
}

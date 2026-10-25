<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025160000 extends AbstractMigration
{
    public function isTransactional(): bool { return false; }

    public function getDescription(): string
    {
        return 'Afzonderlijk instelbare voertuigposten voor chauffeurs per personeelsprofiel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE persoon_chauffeur_post (persoon_id INT NOT NULL, post_id INT NOT NULL, INDEX IDX_PERSOON_CHAUFFEUR_PERSOON (persoon_id), INDEX IDX_PERSOON_CHAUFFEUR_POST (post_id), PRIMARY KEY (persoon_id, post_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE persoon_chauffeur_post ADD CONSTRAINT FK_PERSOON_CHAUFFEUR_PERSOON FOREIGN KEY (persoon_id) REFERENCES persoon (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE persoon_chauffeur_post ADD CONSTRAINT FK_PERSOON_CHAUFFEUR_POST FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE persoon_chauffeur_post');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FrontForce-interventienummer op lijsten en configureerbare verplichting per interventietype.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD interventienummer INT DEFAULT NULL');
        $this->addSql('ALTER TABLE interventie_type ADD interventienummer_verplicht TINYINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP interventienummer');
        $this->addSql('ALTER TABLE interventie_type DROP interventienummer_verplicht');
    }
}

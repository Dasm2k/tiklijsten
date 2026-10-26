<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261026210000 extends AbstractMigration {
    public function isTransactional(): bool { return false; }
    public function getDescription(): string { return 'Instelbare sortering en verzendregistratie voor afsluitmails'; }
    public function up(Schema $schema): void {
        $this->addSql("ALTER TABLE afdeling ADD tikking_sortering VARCHAR(20) NOT NULL DEFAULT 'rang_naam', ADD rang_volgorde VARCHAR(4) NOT NULL DEFAULT 'desc', ADD naam_volgorde VARCHAR(4) NOT NULL DEFAULT 'asc'");
        $this->addSql("CREATE TABLE afsluitmail (id INT AUTO_INCREMENT NOT NULL, lijst_id INT NOT NULL, afdeling_id INT NOT NULL, status VARCHAR(20) NOT NULL, pogingen INT NOT NULL DEFAULT 0, ontvangers LONGTEXT DEFAULT NULL, fout LONGTEXT DEFAULT NULL, aangemaakt_op DATETIME NOT NULL, verzonden_op DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_afsluitmail_lijst (lijst_id), INDEX IDX_afsluitmail_afdeling (afdeling_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }
    public function down(Schema $schema): void {
        $this->addSql('DROP TABLE afsluitmail');
        $this->addSql('ALTER TABLE afdeling DROP tikking_sortering, DROP rang_volgorde, DROP naam_volgorde');
    }
}

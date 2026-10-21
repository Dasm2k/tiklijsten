<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nieuw schema BrandweerOnline (vervangt bw_* tabellen) + posten van de zone';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE aanwezigheidslijst (id INT AUTO_INCREMENT NOT NULL, nummer INT NOT NULL, volgnummer INT DEFAULT NULL, start DATETIME NOT NULL, tweede_start DATETIME DEFAULT NULL, info VARCHAR(100) NOT NULL, peloton INT NOT NULL, afgesloten TINYINT NOT NULL, gearchiveerd TINYINT NOT NULL, afdeling_id INT NOT NULL, post_id INT DEFAULT NULL, gemeente_id INT DEFAULT NULL, interventie_type_id INT DEFAULT NULL, bevelvoerder_id INT DEFAULT NULL, centralist_id INT DEFAULT NULL, INDEX idx_lijst_status (afdeling_id, afgesloten, gearchiveerd), INDEX idx_lijst_start (afdeling_id, start), UNIQUE INDEX uniq_lijst_nummer (afdeling_id, nummer), INDEX IDX_BE6772AAC5BE90E3 (afdeling_id), INDEX IDX_BE6772AA4B89032C (post_id), INDEX IDX_BE6772AA625B653B (gemeente_id), INDEX IDX_BE6772AAA969048 (interventie_type_id), INDEX IDX_BE6772AAC4CBBF5C (bevelvoerder_id), INDEX IDX_BE6772AA936AF155 (centralist_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE afdeling (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(50) NOT NULL, naam VARCHAR(128) NOT NULL, titel VARCHAR(128) NOT NULL, footer VARCHAR(255) NOT NULL, sessieduur INT NOT NULL, aantal_pelotons INT NOT NULL, foutmeldingen_actief TINYINT NOT NULL, voertuigen_actief TINYINT NOT NULL, kadaver_ontvangers VARCHAR(255) DEFAULT NULL, volgorde INT NOT NULL, UNIQUE INDEX UNIQ_4DECB924989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE foutbericht (id INT AUTO_INCREMENT NOT NULL, datum DATETIME NOT NULL, status INT NOT NULL, bericht LONGTEXT NOT NULL, foutmelding_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_50D5426A826DB7A9 (foutmelding_id), INDEX IDX_50D5426A60BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE foutmelding (id INT AUTO_INCREMENT NOT NULL, type INT NOT NULL, wat VARCHAR(128) NOT NULL, probleem LONGTEXT NOT NULL, aangemaakt DATETIME NOT NULL, afgesloten DATETIME DEFAULT NULL, status INT NOT NULL, iedereen_mailen TINYINT NOT NULL, afdeling_id INT NOT NULL, melder_id INT DEFAULT NULL, opvolger_id INT DEFAULT NULL, INDEX idx_fout_status (afdeling_id, status), INDEX IDX_5454A033C5BE90E3 (afdeling_id), INDEX IDX_5454A0335B93B57 (melder_id), INDEX IDX_5454A0338493CBB9 (opvolger_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE gemeente (id INT AUTO_INCREMENT NOT NULL, naam VARCHAR(128) NOT NULL, actief TINYINT NOT NULL, afdeling_id INT NOT NULL, INDEX IDX_BB00390EC5BE90E3 (afdeling_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE herinnering (id INT AUTO_INCREMENT NOT NULL, vraag VARCHAR(255) NOT NULL, afdeling_id INT NOT NULL, INDEX IDX_B192D2A3C5BE90E3 (afdeling_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE interventie_type (id INT AUTO_INCREMENT NOT NULL, naam VARCHAR(128) NOT NULL, volgorde INT NOT NULL, actief TINYINT NOT NULL, in_statistiek TINYINT NOT NULL, afdeling_id INT NOT NULL, INDEX IDX_438E0ACAC5BE90E3 (afdeling_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mailinglijst (id INT AUTO_INCREMENT NOT NULL, naam VARCHAR(128) NOT NULL, omschrijving LONGTEXT NOT NULL, peloton INT DEFAULT NULL, alle_lijsten TINYINT NOT NULL, foutmeldingen TINYINT NOT NULL, afdeling_id INT NOT NULL, INDEX IDX_C6DDFF92C5BE90E3 (afdeling_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mailinglijst_lid (mailinglijst_id INT NOT NULL, persoon_id INT NOT NULL, INDEX IDX_E2474616F390FA73 (mailinglijst_id), INDEX IDX_E247461690FBB45F (persoon_id), PRIMARY KEY (mailinglijst_id, persoon_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE materiaal (id INT AUTO_INCREMENT NOT NULL, naam VARCHAR(128) NOT NULL, keuring DATE DEFAULT NULL, afdeling_id INT NOT NULL, INDEX IDX_A3B1650DC5BE90E3 (afdeling_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE persoon (id INT AUTO_INCREMENT NOT NULL, tiknummer INT NOT NULL, zonenummer INT DEFAULT NULL, code VARCHAR(255) NOT NULL, peloton INT DEFAULT NULL, naam VARCHAR(64) NOT NULL, voornaam VARCHAR(64) NOT NULL, email VARCHAR(180) DEFAULT NULL, actief TINYINT NOT NULL, beroeps TINYINT NOT NULL, rol VARCHAR(20) NOT NULL, sessieduur INT DEFAULT NULL, vast_peloton INT DEFAULT NULL, laatst_gezien DATETIME DEFAULT NULL, systeemaccount TINYINT NOT NULL, afdeling_id INT NOT NULL, rang_id INT DEFAULT NULL, post_id INT DEFAULT NULL, INDEX idx_persoon_naam (naam, voornaam), UNIQUE INDEX uniq_persoon_tiknummer (afdeling_id, tiknummer), INDEX IDX_D8419A4BC5BE90E3 (afdeling_id), INDEX IDX_D8419A4B3CC0D837 (rang_id), INDEX IDX_D8419A4B4B89032C (post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE post (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, naam VARCHAR(128) NOT NULL, straat VARCHAR(128) DEFAULT NULL, huisnummer VARCHAR(16) DEFAULT NULL, postcode VARCHAR(8) DEFAULT NULL, gemeente VARCHAR(128) DEFAULT NULL, UNIQUE INDEX UNIQ_5A8A6C8D77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rang (id INT AUTO_INCREMENT NOT NULL, niveau INT NOT NULL, kort VARCHAR(32) NOT NULL, naam VARCHAR(128) NOT NULL, UNIQUE INDEX UNIQ_81CFA4CE4BDFF36B (niveau), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tikking (id INT AUTO_INCREMENT NOT NULL, start DATETIME DEFAULT NULL, einde DATETIME NOT NULL, lijst_id INT NOT NULL, persoon_id INT NOT NULL, INDEX idx_tikking_persoon (persoon_id, einde), UNIQUE INDEX uniq_tikking (lijst_id, persoon_id), INDEX IDX_C7DB01F79608FE61 (lijst_id), INDEX IDX_C7DB01F790FBB45F (persoon_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE voertuig (id INT AUTO_INCREMENT NOT NULL, naam VARCHAR(128) NOT NULL, roepnaam VARCHAR(128) NOT NULL, nummerplaat VARCHAR(64) NOT NULL, keuring DATE DEFAULT NULL, afdeling_id INT NOT NULL, INDEX IDX_7846EF51C5BE90E3 (afdeling_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD CONSTRAINT FK_BE6772AAC5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD CONSTRAINT FK_BE6772AA4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD CONSTRAINT FK_BE6772AA625B653B FOREIGN KEY (gemeente_id) REFERENCES gemeente (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD CONSTRAINT FK_BE6772AAA969048 FOREIGN KEY (interventie_type_id) REFERENCES interventie_type (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD CONSTRAINT FK_BE6772AAC4CBBF5C FOREIGN KEY (bevelvoerder_id) REFERENCES persoon (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE aanwezigheidslijst ADD CONSTRAINT FK_BE6772AA936AF155 FOREIGN KEY (centralist_id) REFERENCES persoon (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE foutbericht ADD CONSTRAINT FK_50D5426A826DB7A9 FOREIGN KEY (foutmelding_id) REFERENCES foutmelding (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE foutbericht ADD CONSTRAINT FK_50D5426A60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES persoon (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE foutmelding ADD CONSTRAINT FK_5454A033C5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE foutmelding ADD CONSTRAINT FK_5454A0335B93B57 FOREIGN KEY (melder_id) REFERENCES persoon (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE foutmelding ADD CONSTRAINT FK_5454A0338493CBB9 FOREIGN KEY (opvolger_id) REFERENCES persoon (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE gemeente ADD CONSTRAINT FK_BB00390EC5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE herinnering ADD CONSTRAINT FK_B192D2A3C5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE interventie_type ADD CONSTRAINT FK_438E0ACAC5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mailinglijst ADD CONSTRAINT FK_C6DDFF92C5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mailinglijst_lid ADD CONSTRAINT FK_E2474616F390FA73 FOREIGN KEY (mailinglijst_id) REFERENCES mailinglijst (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mailinglijst_lid ADD CONSTRAINT FK_E247461690FBB45F FOREIGN KEY (persoon_id) REFERENCES persoon (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE materiaal ADD CONSTRAINT FK_A3B1650DC5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE persoon ADD CONSTRAINT FK_D8419A4BC5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE persoon ADD CONSTRAINT FK_D8419A4B3CC0D837 FOREIGN KEY (rang_id) REFERENCES rang (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE persoon ADD CONSTRAINT FK_D8419A4B4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tikking ADD CONSTRAINT FK_C7DB01F79608FE61 FOREIGN KEY (lijst_id) REFERENCES aanwezigheidslijst (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tikking ADD CONSTRAINT FK_C7DB01F790FBB45F FOREIGN KEY (persoon_id) REFERENCES persoon (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE voertuig ADD CONSTRAINT FK_7846EF51C5BE90E3 FOREIGN KEY (afdeling_id) REFERENCES afdeling (id) ON DELETE CASCADE');

        // Posten van Brandweerzone Rivierenland (uit brandw_bwz.bwz_post).
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('BO', 'Boom', 'Dijleweg', '5', '2850', 'Boom')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('RU', 'Rumst', 'Steenberghoekstraat', '24', '2840', 'Rumst')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('BE', 'Berlaar', 'Liersesteenweg', '51', '2590', 'Berlaar')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('BM', 'Bornem', 'Puursesteenweg', '147', '2880', 'Bornem')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('DU', 'Duffel', 'Spoorweglaan', '2', '2570', 'Duffel')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('HB', 'Heist-op-den-Berg', 'Broekstraat', '46', '2220', 'Heist-op-den-Berg')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('HM', 'Hemiksem', 'Georges Gilliotstraat', '50', '2660', 'Hemiksem')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('LR', 'Lier', 'Hoogveldweg', '97', '2500', 'Lier')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('ME', 'Mechelen (Nekker)', 'Plattebeekstraat', '11', '2800', 'Mechelen')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('NL', 'Nijlen', 'Kerkstraat', '4', '2560', 'Nijlen')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('NI', 'Niel', 'Volkenbondstraat', '80', '2845', 'Niel')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('PT', 'Putte', 'Mechelbaan', '361', '2580', 'Putte')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('PU', 'Puurs', 'Kleine Amer', '22', '2870', 'Puurs')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('WL', 'Willebroek', 'Bedrijvenpark-De Veert', '9-10', '2830', 'Willebroek')");
        $this->addSql("INSERT INTO post (code, naam, straat, huisnummer, postcode, gemeente) VALUES ('SA', 'Sint-Amands', 'Hemelrijken', '17', '2890', 'Sint-Amands')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AAC5BE90E3');
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AA4B89032C');
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AA625B653B');
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AAA969048');
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AAC4CBBF5C');
        $this->addSql('ALTER TABLE aanwezigheidslijst DROP FOREIGN KEY FK_BE6772AA936AF155');
        $this->addSql('ALTER TABLE foutbericht DROP FOREIGN KEY FK_50D5426A826DB7A9');
        $this->addSql('ALTER TABLE foutbericht DROP FOREIGN KEY FK_50D5426A60BB6FE6');
        $this->addSql('ALTER TABLE foutmelding DROP FOREIGN KEY FK_5454A033C5BE90E3');
        $this->addSql('ALTER TABLE foutmelding DROP FOREIGN KEY FK_5454A0335B93B57');
        $this->addSql('ALTER TABLE foutmelding DROP FOREIGN KEY FK_5454A0338493CBB9');
        $this->addSql('ALTER TABLE gemeente DROP FOREIGN KEY FK_BB00390EC5BE90E3');
        $this->addSql('ALTER TABLE herinnering DROP FOREIGN KEY FK_B192D2A3C5BE90E3');
        $this->addSql('ALTER TABLE interventie_type DROP FOREIGN KEY FK_438E0ACAC5BE90E3');
        $this->addSql('ALTER TABLE mailinglijst DROP FOREIGN KEY FK_C6DDFF92C5BE90E3');
        $this->addSql('ALTER TABLE mailinglijst_lid DROP FOREIGN KEY FK_E2474616F390FA73');
        $this->addSql('ALTER TABLE mailinglijst_lid DROP FOREIGN KEY FK_E247461690FBB45F');
        $this->addSql('ALTER TABLE materiaal DROP FOREIGN KEY FK_A3B1650DC5BE90E3');
        $this->addSql('ALTER TABLE persoon DROP FOREIGN KEY FK_D8419A4BC5BE90E3');
        $this->addSql('ALTER TABLE persoon DROP FOREIGN KEY FK_D8419A4B3CC0D837');
        $this->addSql('ALTER TABLE persoon DROP FOREIGN KEY FK_D8419A4B4B89032C');
        $this->addSql('ALTER TABLE tikking DROP FOREIGN KEY FK_C7DB01F79608FE61');
        $this->addSql('ALTER TABLE tikking DROP FOREIGN KEY FK_C7DB01F790FBB45F');
        $this->addSql('ALTER TABLE voertuig DROP FOREIGN KEY FK_7846EF51C5BE90E3');
        $this->addSql('DROP TABLE aanwezigheidslijst');
        $this->addSql('DROP TABLE afdeling');
        $this->addSql('DROP TABLE foutbericht');
        $this->addSql('DROP TABLE foutmelding');
        $this->addSql('DROP TABLE gemeente');
        $this->addSql('DROP TABLE herinnering');
        $this->addSql('DROP TABLE interventie_type');
        $this->addSql('DROP TABLE mailinglijst');
        $this->addSql('DROP TABLE mailinglijst_lid');
        $this->addSql('DROP TABLE materiaal');
        $this->addSql('DROP TABLE persoon');
        $this->addSql('DROP TABLE post');
        $this->addSql('DROP TABLE rang');
        $this->addSql('DROP TABLE tikking');
        $this->addSql('DROP TABLE voertuig');
    }
}

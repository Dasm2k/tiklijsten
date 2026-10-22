<?php

/** Zelfstandige controles: uitsluitend een tijdelijke SQLite-database in geheugen. */
declare(strict_types=1);

use App\Command\ImportLegacyCommand;
use App\Import\LegacyImporter;
use App\Import\LegacySqlDumpBron;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Tester\CommandTester;

$root = dirname(__DIR__);
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('App\\Import\\', $root.'/src/Import/', true);
$loader->addPsr4('App\\Command\\', $root.'/src/Command/', true);
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Deze geïsoleerde regressietest gebruikt pdo_sqlite. De echte dumpimport heeft die extensie niet nodig.\n");
    exit(1);
}
set_error_handler(static function (int $niveau, string $melding, string $bestand, int $regel): bool {
    if (error_reporting() & $niveau) { throw new ErrorException($melding, 0, $niveau, $bestand, $regel); }
    return false;
});
$tijdelijk = []; $totaal = 0;

function controle(bool $ok, string $melding): void
{
    if (!$ok) { throw new RuntimeException($melding); }
}
function scenario(string $naam, callable $test): void
{
    global $totaal;
    $test(); ++$totaal; echo 'OK '.$totaal.' '.$naam.PHP_EOL;
}
function dumpbestand(string $sql, bool $gzip = false): string
{
    global $tijdelijk;
    $pad = tempnam(sys_get_temp_dir(), 'tik-dump-test-');
    if (false === $pad) { throw new RuntimeException('Geen tijdelijk testbestand beschikbaar.'); }
    $tijdelijk[] = $pad;
    if ($gzip) {
        $gecomprimeerd = $pad.'.gz';
        $tijdelijk[] = $gecomprimeerd;
        file_put_contents($gecomprimeerd, gzencode($sql));
        return $gecomprimeerd;
    }
    file_put_contents($pad, $sql);
    return $pad;
}
function rijen(string $sql, string $tabel = 'bw_users', ?string $database = null): array
{
    $bron = new LegacySqlDumpBron(dumpbestand($sql), $database);
    try { return iterator_to_array($bron->rijen($tabel)); }
    finally { $bron->sluiten(); }
}
function verwachtFout(callable $actie, string $fragment): void
{
    try { $actie(); }
    catch (Throwable $e) {
        controle(str_contains($e->getMessage(), $fragment), 'Andere fout dan verwacht: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Verwachte fout bleef uit: '.$fragment);
}
function momentopname(\Doctrine\DBAL\Connection $db): array
{
    $resultaat = [];
    foreach ($db->createSchemaManager()->listTableNames() as $tabel) {
        $rijen = $db->fetchAllAssociative('SELECT * FROM '.$db->quoteIdentifier($tabel));
        usort($rijen, static fn ($a, $b) => serialize($a) <=> serialize($b));
        $resultaat[$tabel] = $rijen;
    }
    ksort($resultaat);
    return $resultaat;
}

try {
    $fixture = $root.'/tests/fixtures/legacy-dump/boom.sql';
    $sql = file_get_contents($fixture);
    $bron = new LegacySqlDumpBron($fixture);
    scenario('mysqldump/phpMyAdmin: schema, kolommen en uitgebreide INSERTs', static function () use ($bron): void {
        controle(15 === count($bron->aantallen()), 'Niet alle brontabellen herkend.');
        controle(5 === $bron->aantallen()['bw_users'], 'Verkeerd aantal personen.');
        controle(!$bron->heeftTabel('persoon'), 'Niet-legacy SQL werd als bron geaccepteerd.');
    });
    scenario('ongeschikte SQL uit dump wordt niet op doel uitgevoerd', static function () use ($bron): void {
        controle(!$bron->heeftTabel('persoon') && $bron->heeftTabel('bw_users'), 'Alleen bekende legacytabellen mogen gegevens leveren.');
    });
    scenario('rijen worden ook bij ongesorteerde INSERTs op id gelezen', static function () use ($bron): void {
        controle([1, 2, 3, 4, 5] === array_map(static fn ($r) => (int) $r['id'], iterator_to_array($bron->rijen('bw_aanwezigen'))), 'Id-volgorde ging verloren.');
    });
    scenario('latin1-kolommen en oude UTF-8-in-latin1 blijven correct', static function () use ($bron): void {
        $persoon = iterator_to_array($bron->rijen('bw_users'))[0];
        controle('François' === LegacyImporter::tekst($persoon['naam']), 'Mojibake uit dump is niet teruggebracht naar opgeslagen bytes.');
        controle("O'Brien" === $persoon['voornaam'], 'Apostrof verkeerd gedecodeerd.');
    });
    scenario('SQL-achtige tekst, puntkomma en HTML blijven gegevens', static function () use ($bron): void {
        $fout = iterator_to_array($bron->rijen('bw_fouten'))[0];
        controle(str_contains($fout['probleem'], 'DROP TABLE persoon; -- geen SQL'), 'Tekst werd als SQL behandeld.');
        controle(str_contains(LegacyImporter::tekst($fout['probleem']), "O'Brien & pomp"), 'Tekstconversie ging verloren.');
    });
    scenario('CREATE TABLE en INSERT zonder kolomnamen', static function (): void {
        $r = rijen("CREATE TABLE bw_users (id int, naam text DEFAULT ')', PRIMARY KEY (id)); INSERT INTO bw_users VALUES (1,'test');");
        controle('test' === $r[0]['naam'], 'Kolomvolgorde uit CREATE werd niet gebruikt.');
    });
    scenario('dubbele apostrofs en dubbele aanhalingstekens in SQL', static function (): void {
        $r = rijen("INSERT INTO bw_users (id,naam,voornaam) VALUES (1,'O''Brien',\"test\");");
        controle("O'Brien" === $r[0]['naam'] && 'test' === $r[0]['voornaam'], 'SQL-aanhalingstekens verkeerd verwerkt.');
    });
    scenario('MySQL-backslashescapes, NULL en letterlijke NULL', static function (): void {
        $r = rijen(<<<'SQL'
INSERT INTO bw_users (id,naam,voornaam,code) VALUES (1,'a\nb\rc\td\0e\Zf\\g\%h\_i',NULL,'NULL');
SQL);
        controle("a\nb\rc\td\0e\x1Af\\g\\%h\\_i" === $r[0]['naam'], 'Backslashescapes verkeerd gedecodeerd.');
        controle(null === $r[0]['voornaam'] && 'NULL' === $r[0]['code'], 'NULL verward met tekst.');
    });
    scenario('NO_BACKSLASH_ESCAPES uit SQL_MODE', static function (): void {
        $r = rijen(<<<'SQL'
/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;
INSERT INTO bw_users (id,naam) VALUES (1,'C:\new\file');
SQL);
        controle('C:\\new\\file' === $r[0]['naam'], 'SQL_MODE werd niet gevolgd.');
    });
    scenario('ANSI_QUOTES-identifiers', static function (): void {
        $r = rijen("SET SQL_MODE='ANSI_QUOTES'; CREATE TABLE \"bw_users\" (\"id\" int, \"naam\" text); INSERT INTO \"bw_users\" VALUES (1,'goed');");
        controle('goed' === $r[0]['naam'], 'ANSI_QUOTES ging mis.');
    });
    scenario('kolomtekenset gaat voor tabeltekenset', static function (): void {
        $r = rijen("SET NAMES utf8mb4; CREATE TABLE bw_users (id int, naam text CHARACTER SET utf8mb4) DEFAULT CHARSET=latin1; INSERT INTO bw_users VALUES (1,'Brandweer 🚒');");
        controle('Brandweer 🚒' === $r[0]['naam'], 'Kolomtekenset werd genegeerd.');
    });
    scenario('hex, binaire literals en charset-introducers', static function (): void {
        $r = rijen("INSERT INTO bw_users (id,a,b,c,d) VALUES (1,0x4142,X'4344',_binary 'EF',_utf8mb4 'é');");
        controle(['AB', 'CD', 'EF', 'é'] === [$r[0]['a'], $r[0]['b'], $r[0]['c'], $r[0]['d']], 'Binaire literal verkeerd gedecodeerd.');
    });
    scenario('numerieke, exponent-, boolean- en bitwaarden', static function (): void {
        $r = rijen("INSERT INTO bw_users (id,a,b,c,d,e) VALUES (1,-2,1.5e-3,TRUE,FALSE,b'101');");
        controle(['-2', '1.5e-3', '1', '0', '5'] === [$r[0]['a'], $r[0]['b'], $r[0]['c'], $r[0]['d'], $r[0]['e']], 'Numerieke literals verkeerd verwerkt.');
    });
    scenario('bom, regelcommentaar en blockcommentaar', static function (): void {
        $r = rijen("\xEF\xBB\xBF-- test\n# test\n/* test */ INSERT INTO bw_users (id,naam) VALUES (1,'-- # /* */');");
        controle('-- # /* */' === $r[0]['naam'], 'Commentaar in tekst werd verwijderd.');
    });
    scenario('gzip-dump', static function () use ($sql): void {
        $b = new LegacySqlDumpBron(dumpbestand($sql, true));
        controle(5 === $b->aantallen()['bw_users'], 'Gzip-dump verkeerd gelezen.');
        $b->sluiten();
    });
    scenario('meerdere databases vereisen een expliciete keuze', static function (): void {
        $sql = "USE a; INSERT INTO bw_users (id,naam) VALUES (1,'A'); USE b; INSERT INTO bw_users (id,naam) VALUES (1,'B');";
        verwachtFout(static fn () => rijen($sql), 'meerdere brondatabases');
        controle('B' === rijen($sql, 'bw_users', 'b')[0]['naam'], 'Verkeerde database gekozen.');
    });
    scenario('database-gekwalificeerde tabelnaam', static function (): void {
        controle('A' === rijen("INSERT INTO `oud`.`bw_users` (`id`,`naam`) VALUES (1,'A');", 'bw_users', 'oud')[0]['naam'], 'Gekwalificeerde naam verkeerd gelezen.');
    });
    scenario('dubbele ids worden geweigerd', static function (): void {
        verwachtFout(static fn () => rijen("INSERT INTO bw_users (id) VALUES (1),(2),(1);"), 'Dubbele id');
    });
    scenario('ontbrekende kolommenlijst wordt duidelijk gemeld', static function (): void {
        verwachtFout(static fn () => rijen('INSERT INTO bw_users VALUES (1);'), 'kolommenlijst');
    });
    scenario('afgebroken dump wordt geweigerd', static function (): void {
        verwachtFout(static fn () => rijen("INSERT INTO bw_users (id,naam) VALUES (1,'geheim"), 'Onvolledige tekstwaarde');
        verwachtFout(static fn () => rijen('INSERT INTO bw_users (id) VALUES (1)'), 'midden in een SQL-opdracht');
    });
    scenario('kolomaantallen en SQL-expressies worden gecontroleerd', static function (): void {
        verwachtFout(static fn () => rijen('INSERT INTO bw_users (id,naam) VALUES (1);'), 'Verwacht ,');
        verwachtFout(static fn () => rijen('INSERT INTO bw_users (id) VALUES (1,2);'), 'Verwacht )');
        verwachtFout(static fn () => rijen('INSERT INTO bw_users (id,naam) VALUES (1,NOW());'), 'letterlijke INSERT');
    });
    scenario('onondersteunde mutaties en routines worden niet stil overgeslagen', static function (): void {
        verwachtFout(static fn () => rijen('UPDATE bw_users SET naam=1;'), 'gegevenswijzigingen');
        verwachtFout(static fn () => rijen('DELIMITER $$'), 'gegevenswijzigingen');
        verwachtFout(static fn () => rijen('INSERT IGNORE INTO bw_users (id) VALUES (1);'), 'Verwacht INTO');
    });
    scenario('grote tekstwaarde over de leesbuffergrens', static function (): void {
        $tekst = str_repeat("regel; waarde, ", 7000);
        controle($tekst === rijen("INSERT INTO bw_users (id,naam) VALUES (1,'".$tekst."');")[0]['naam'], 'Tekst op buffergrens beschadigd.');
    });

    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('PRAGMA foreign_keys = ON');
    $config = ORMSetup::createAttributeMetadataConfiguration([$root.'/src/Entity'], true);
    $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
    if (PHP_VERSION_ID >= 80400) { $config->enableNativeLazyObjects(true); }
    $em = new EntityManager($db, $config);
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $db->insert('post', ['code' => 'BO', 'naam' => 'Boom', 'gemeente' => 'Boom']);
    $db->insert('post', ['code' => 'RU', 'naam' => 'Rumst', 'gemeente' => 'Rumst']);
    $importer = new LegacyImporter($em);
    $tikPcs = [1000 => ['post' => 'Boom', 'peloton' => 1], 2000 => ['post' => 'Rumst', 'peloton' => 5]];
    $log = static function (string $regel): void {};
    scenario('controlemodus schrijft geen enkel doelrecord', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $voor = momentopname($db);
        $aantallen = $importer->importeer($fixture, 'boom-rumst', 'Post Boom/Rumst', 1, $tikPcs, false, $log, true);
        controle(5 === $aantallen['bw_users'] && $voor === momentopname($db), 'Controlemodus heeft doelgegevens gewijzigd.');
    });
    scenario('ontbrekende brontabellen stoppen voor doelwijzigingen', static function () use ($db, $importer, $tikPcs, $log): void {
        $voor = momentopname($db);
        verwachtFout(static fn () => $importer->importeer(dumpbestand('INSERT INTO bw_users (id) VALUES (1);'), 'boom-rumst', 'Boom', 1, $tikPcs, false, $log), 'Vereiste brontabel ontbreekt');
        controle($voor === momentopname($db), 'Ongeldige bron veranderde de doeldatabase.');
    });
    scenario('volledige omzetting met echte DBAL en foreign keys', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $a = $importer->importeer($fixture, 'boom-rumst', 'Post Boom/Rumst', 1, $tikPcs, false, $log);
        controle(5 === $a['personen'] && 2 === $a['tikkingen'] && 3 === $a['tikkingen_overgeslagen'], 'Tellers kloppen niet.');
        controle(1 === $a['onbekende_personen_aangemaakt'] && 1 === $a['foutmeldingen'] && 1 === $a['foutberichten'], 'Historiek is onvolledig.');
        controle(6 === (int) $db->fetchOne('SELECT COUNT(*) FROM persoon'), 'Onbekende melder ontbreekt.');
        controle('François' === $db->fetchOne('SELECT naam FROM persoon WHERE tiknummer = 42'), 'Naam verkeerd omgezet.');
        controle(md5('1234') === $db->fetchOne('SELECT code FROM persoon WHERE tiknummer = 42'), 'Bestaande codehash veranderd.');
        controle('post' === $db->fetchOne('SELECT rol FROM persoon WHERE tiknummer = 9001'), 'Postrol verloren.');
        controle(5 === (int) $db->fetchOne('SELECT vast_peloton FROM persoon WHERE tiknummer = 9002'), 'Vast peloton verloren.');
        controle(0 === (int) $db->fetchOne('SELECT actief FROM persoon WHERE tiknummer = 777'), 'Onbekende persoon is actief.');
        controle(0 === (int) $db->fetchOne('SELECT actief FROM persoon WHERE tiknummer = 43'), 'Inactieve persoon is geactiveerd.');
        controle('2026-10-21 18:00:00' === $db->fetchOne('SELECT t.einde FROM tikking t JOIN persoon p ON p.id=t.persoon_id WHERE p.tiknummer=42'), 'Dubbele tikking met laagste legacy-id is niet behouden.');
        controle(42 === (int) $db->fetchOne('SELECT p.tiknummer FROM foutmelding f JOIN persoon p ON p.id=f.melder_id'), 'Foutmelding aan verkeerde melder gekoppeld.');
        controle(43 === (int) $db->fetchOne('SELECT p.tiknummer FROM foutbericht b JOIN persoon p ON p.id=b.auteur_id'), 'Foutbericht aan verkeerde auteur gekoppeld.');
        controle(2 === (int) $db->fetchOne('SELECT COUNT(*) FROM mailinglijst_lid'), 'Mailingleden niet correct ontdubbeld.');
        controle(1 === (int) $db->fetchOne('SELECT foutmeldingen_actief FROM afdeling'), 'Module-instelling verloren.');
    });
    scenario('bestaande afdeling wordt standaard behouden', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $voor = momentopname($db);
        verwachtFout(static fn () => $importer->importeer($fixture, 'boom-rumst', 'Boom', 1, $tikPcs, false, $log), 'bestaat al');
        controle($voor === momentopname($db), 'Bestaande gegevens gewijzigd zonder --vervang.');
    });
    scenario('controle van een vervanging bewaart ook bestaande gegevens', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $voor = momentopname($db);
        $importer->importeer($fixture, 'boom-rumst', 'Test', 1, $tikPcs, true, $log, true);
        controle($voor === momentopname($db), '--controleer --vervang heeft bestaande gegevens gewijzigd.');
    });
    scenario('dezelfde importer kan meerdere afdelingen verwerken', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $a = $importer->importeer($fixture, 'niel', 'Post Niel', 2, $tikPcs, false, $log);
        controle(5 === $a['personen'] && 12 === (int) $db->fetchOne('SELECT COUNT(*) FROM persoon'), 'Afdelingsmappings lopen door elkaar.');
    });
    scenario('mislukte vervanging herstelt de hele oude afdeling', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $voor = momentopname($db);
        $db->executeStatement("CREATE TRIGGER import_test_fout BEFORE INSERT ON tikking BEGIN SELECT RAISE(ABORT, 'geforceerde testfout'); END");
        verwachtFout(static fn () => $importer->importeer($fixture, 'boom-rumst', 'Vervanging', 1, $tikPcs, true, $log), 'geforceerde testfout');
        $db->executeStatement('DROP TRIGGER import_test_fout');
        controle($voor === momentopname($db), 'Rollback heeft oude of andere afdelingsgegevens niet hersteld.');
    });
    scenario('geslaagde vervanging raakt andere afdelingen niet', static function () use ($db, $importer, $fixture, $tikPcs, $log): void {
        $niel = $db->fetchOne("SELECT id FROM afdeling WHERE slug='niel'");
        $nielPersonen = $db->fetchAllAssociative('SELECT * FROM persoon WHERE afdeling_id = ? ORDER BY id', [$niel]);
        $importer->importeer($fixture, 'boom-rumst', 'Post Boom/Rumst nieuw', 1, $tikPcs, true, $log);
        controle($nielPersonen === $db->fetchAllAssociative('SELECT * FROM persoon WHERE afdeling_id = ? ORDER BY id', [$niel]), 'Andere afdeling werd aangepast.');
        controle(2 === (int) $db->fetchOne('SELECT COUNT(*) FROM afdeling') && 12 === (int) $db->fetchOne('SELECT COUNT(*) FROM persoon'), 'Vervanging maakte extra records.');
    });
    scenario('console herkent bestand en controleoptie', static function () use ($db, $importer, $fixture): void {
        $voor = momentopname($db);
        $tester = new CommandTester(new ImportLegacyCommand($importer));
        $status = $tester->execute(['afdeling' => 'jeugd', 'bron' => $fixture, '--controleer' => true], ['interactive' => false]);
        controle(0 === $status && str_contains($tester->getDisplay(), 'Controle van'), 'Consolecontrole werkt niet.');
        controle($voor === momentopname($db), 'Consolecontrole heeft gegevens gewijzigd.');
    });
    scenario('ontbrekend doelschema wordt gemeld zonder tabellen aan te maken', static function () use ($config, $fixture, $tikPcs, $log): void {
        $leeg = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $leegEm = new EntityManager($leeg, $config);
        verwachtFout(static fn () => (new LegacyImporter($leegEm))->importeer($fixture, 'leeg', 'Leeg', 1, $tikPcs, false, $log), 'Symfony-tabellen ontbreken');
        controle([] === $leeg->createSchemaManager()->listTableNames(), 'Importer heeft zelf tabellen aangemaakt.');
    });
    scenario('meer dan duizend tikkingen worden in batches geschreven', static function () use ($db, $importer, $sql, $tikPcs, $log): void {
        $extra = 'INSERT INTO bw_aanwezigen (id,interventie,user,datum,uur,begindatum,beginuur) VALUES ';
        for ($i = 0; $i < 1001; ++$i) {
            $extra .= ($i ? ',' : '').'('.(100 + $i).',10,'.(10000 + $i).",'2026-10-21','18:00:00','2026-10-21','17:00:00')";
        }
        $a = $importer->importeer(dumpbestand($sql.$extra.';'), 'grote-import', 'Grote import', 4, $tikPcs, false, $log);
        controle(1003 === $a['tikkingen'] && 1002 === $a['onbekende_personen_aangemaakt'], 'Batchgrens heeft tikkingen of personen verloren.');
        controle(1003 === (int) $db->fetchOne("SELECT COUNT(*) FROM tikking t JOIN aanwezigheidslijst l ON l.id=t.lijst_id JOIN afdeling a ON a.id=l.afdeling_id WHERE a.slug='grote-import'"), 'Batchtellers stemmen niet met de database overeen.');
    });
    scenario('afdelingen zonder optionele modules worden geïmporteerd', static function () use ($db, $importer, $sql, $tikPcs, $log): void {
        $kern = substr($sql, 0, strpos($sql, 'CREATE TABLE `bw_mailing`')).'COMMIT;';
        $a = $importer->importeer(dumpbestand($kern), 'jeugd', 'Jeugdbrandweer', 3, $tikPcs, false, $log);
        controle(0 === $a['mailinglijsten'] && 0 === $a['foutmeldingen'], 'Ontbrekende modules worden toch verwacht.');
        controle(0 === (int) $db->fetchOne("SELECT foutmeldingen_actief FROM afdeling WHERE slug='jeugd'"), 'Ontbrekende foutmodule werd ingeschakeld.');
    });
    scenario('grote uitgebreide INSERT wordt met beperkt geheugen verwerkt', static function (): void {
        $pad = dumpbestand('');
        $f = fopen($pad, 'wb');
        fwrite($f, 'INSERT INTO bw_aanwezigen (id,user) VALUES ');
        for ($i = 1; $i <= 50000; ++$i) { fwrite($f, ($i > 1 ? ',' : '').'('.$i.',42)'); }
        fwrite($f, ';'); fclose($f);
        $voor = memory_get_usage(true);
        $b = new LegacySqlDumpBron($pad);
        controle(50000 === $b->aantallen()['bw_aanwezigen'], 'Grote INSERT is afgebroken.');
        controle(memory_get_usage(true) - $voor < 16777216, 'Grote INSERT wordt geheel in geheugen gehouden.');
        $aantal = 0; foreach ($b->rijen('bw_aanwezigen') as $rij) { ++$aantal; }
        controle(50000 === $aantal, 'Tijdelijke rijopslag onvolledig.');
        $b->sluiten();
    });
    echo PHP_EOL.$totaal.' regressiescenario’s geslaagd op PHP '.PHP_VERSION.'.'.PHP_EOL;
} finally {
    foreach ($tijdelijk as $pad) { if (is_file($pad)) { unlink($pad); } }
}

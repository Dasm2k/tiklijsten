# SQL-dump rechtstreeks importeren in Tikkingen

Dit pakket is een aanvulling op je bestaande Symfony 8.1-project. Het vervangt
alleen de legacy-importer en het bijbehorende consolecommando, en voegt een
dump-lezer en regressietest toe. Composer.json, Composer.lock, je omgevingsbestanden
en de overige appfuncties worden niet vervangen.

De bron is nu een lokaal `.sql`- of `.sql.gz`-bestand. De importer leest de oude
`bw_*`-gegevens en schrijft ze volgens de bestaande omzettingen naar de huidige
Symfony-database uit `DATABASE_URL`. Er is geen extra database nodig, er worden
geen stagingtabellen aangemaakt en de echte import vereist geen SQLite-extensie.

SQL uit het bronbestand wordt nooit op de doeldatabase uitgevoerd. Ook `CREATE
DATABASE`, `USE`, `DROP TABLE`, tabelinstellingen, locks en privileges uit de dump
worden niet naar de doeldatabase doorgestuurd. De lezer gebruikt tijdelijke private
rijbestanden in de PHP-tempmap; deze worden bij afsluiten verwijderd. Er moet
daarom wel voldoende tijdelijke schijfruimte beschikbaar zijn voor de uitgepakte
rijgegevens. De oorspronkelijke dump blijft ongewijzigd.

## Installeren via jouw Git-repository

Pak het pakket lokaal uit in de Symfony-projectmap (waar `composer.json` staat):

```bash
tar -xzf tikkingen-sql-dump-importer.tar.gz
ddev exec php bin/console lint:container
ddev exec php bin/console app:import-legacy --help
```

Commit de wijzigingen en push ze naar GitHub:

```bash
git add src/Import/ src/Command/ImportLegacyCommand.php tools/sql-dump-regressie.php tests/fixtures/legacy-dump/boom.sql LEESMIJ-sql-dump-importer.md
git commit -m "Importeer legacy SQL-dumps rechtstreeks"
git push
```

Haal ze daarna op de server binnen:

```bash
cd /data/sites/web/brandweerboombe/subsites/tiklijst.brandweerboom.be
git pull --ff-only origin main
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
APP_ENV=prod APP_DEBUG=0 php bin/console lint:container
APP_ENV=prod APP_DEBUG=0 php bin/console app:import-legacy --help
```

Er is geen Composer-update of nieuwe schema-migratie voor deze wijziging nodig.
De bestaande Symfony-tabellen moeten wel al bestaan. Als je project een
authoritative Composer-classmap gebruikt, vernieuw je die voor het leegmaken van
de cache met `composer dump-autoload --no-dev --optimize --no-scripts`.

De bestanden onder `tests/fixtures/` bevatten uitsluitend fictieve testgegevens.
Plaats je eigen dump buiten `public/`, bijvoorbeeld in `var/import/`; deze hoeft
niet in Git te worden opgenomen.

## Boom/Rumst: eerst controleren

Upload je eigen SQL-dump als `var/import/boom-rumst.sql` (of pas het pad hieronder
aan naar de echte bestandsnaam). Voer vervolgens uit vanuit de projectmap:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:import-legacy boom-rumst \
  var/import/boom-rumst.sql \
  --naam='Post Boom/Rumst' \
  --volgorde=1 \
  --tikpc=1000:Boom:1 \
  --tikpc=2000:Rumst:5 \
  --controleer
```

Dit leest de volledige dump, controleert vereiste tabellen en kolommen, dubbele
legacy-id's, de aanwezigheid van de Symfony-doeltabellen en voor MySQL/MariaDB
de InnoDB-engines. Het toont aantallen per brontabel. Er worden geen doelrecords
geschreven, verwijderd of aangepast. Dit is een bron- en schemacontrole; het
simuleert niet alle SQL-constraints van een echte import.

Als de controle slaagt, maak je een back-up van de huidige Symfony-database.
Voer daarna hetzelfde commando uit zonder `--controleer`:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:import-legacy boom-rumst \
  var/import/boom-rumst.sql \
  --naam='Post Boom/Rumst' \
  --volgorde=1 \
  --tikpc=1000:Boom:1 \
  --tikpc=2000:Rumst:5
```

Er zijn geen gebruikersnaam, wachtwoord of SSH-tunnel voor een brondatabase nodig.
De bestaande `DATABASE_URL` moet naar de Symfony-doeldatabase blijven verwijzen.

## Als de afdeling al bestaat

Standaard stopt de importer wanneer `boom-rumst` al bestaat. Dit voorkomt dubbele
of onbedoeld overschreven gegevens. Alleen als je deze afdeling bewust volledig
wilt vervangen, voeg je `--vervang` toe. Daarmee verdwijnen ook eventuele nieuwe
personen, lijsten en foutmeldingen in die afdeling die niet in de dump voorkomen.
Andere afdelingen en gedeelde posten/rangen worden niet verwijderd.

Je kunt eerst dezelfde import controleren met zowel `--vervang` als `--controleer`:
dat verwijdert nog niets. De daadwerkelijke vervanging gebeurt binnen één
InnoDB-transactie. Als de import faalt, wordt ook het verwijderen van de bestaande
afdeling teruggedraaid. De tabellen moeten hiervoor InnoDB gebruiken; de importer
stopt vooraf wanneer hij een andere engine aantreft. Een mislukte echte import kan
wel MySQL-auto-incrementnummers verbruiken, ook wanneer alle records teruggedraaid
zijn.

## Andere afdelingen en meerdere databases in één dump

Jeugdbrandweer (pas tik-pc-koppelingen aan indien nodig):

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:import-legacy jeugd \
  var/import/jeugd.sql.gz --naam='Jeugdbrandweer' --volgorde=3 --controleer
```

Niel:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:import-legacy niel \
  var/import/niel.sql --naam='Post Niel' --volgorde=2 --controleer
```

Bevat één dump meerdere databases met eigen `USE`-opdrachten, selecteer dan de
oorspronkelijke databasenaam met bijvoorbeeld `--bron-database=brandw_project`.
Die naam is uitsluitend een selectie binnen het bestand; er wordt geen verbinding
naar die oude database gemaakt en er wordt geen database met die naam aangemaakt.
Zonder expliciete keuze wordt een dump met meerdere legacydatabases geweigerd.

De bestaande import vanaf een bereikbare `mysql://`- of `mariadb://`-bron blijft
ondersteund, maar voor jouw dump is alleen het bestandspad nodig.

## Ondersteuning en omzettingen

Ondersteund worden gebruikelijke volledige MySQL-/MariaDB-dumps van mysqldump en
phpMyAdmin: `CREATE TABLE`, `INSERT INTO ... VALUES` met of zonder kolomnamen,
meerdere rijen per INSERT, SQL-commentaar, gequote identifiers, apostrofs en
backslashescapes, `NULL`, numerieke waarden, hex-/binaire literals, `SET NAMES`,
`NO_BACKSLASH_ESCAPES`, `ANSI_QUOTES` en `.sql.gz`.

De tabel- en kolomtekensets uit de dump worden gebruikt om dezelfde opgeslagen
bytes terug te krijgen die de bestaande live-importer via latin1 las. Hierdoor
blijft ook de correctie van UTF-8-in-latin1, HTML-entiteiten en oude HTML-opmaak
behouden. Er is ondersteuning voor utf8/utf8mb3/utf8mb4, latin1/cp1252, ascii,
latin2 en binaire waarden. Een data-only export met expliciete kolomnamen kan
worden gelezen, maar geeft een waarschuwing omdat kolomtekensets dan onbekend zijn.
Gebruik voor de beste tekstomzetting een volledige export met tabeldefinities.

De importer behoudt de bestaande omzettingen voor accounts en codehashes, rollen,
tik-pc's, pelotons, lijsten, tikkingen, rangen, gemeenten, interventietypes,
mailinglijsten, voertuigen, materiaal, herinneringen, foutmeldingen en berichten.
Verwijderde personen met historische tikkingen worden zoals voorheen als inactief
"Onbekend" aangemaakt. Dubbele tikkingen per persoon/lijst worden ontdubbeld op
de laagste oude id. De bijbehorende tellers worden getoond. Postnamen worden
gekoppeld aan de bestaande gedeelde posten in de Symfony-database; ontbrekende
postkoppelingen bij tik-pc's geven dezelfde waarschuwing als de oude importer.

De vereiste oude tabellen zijn `bw_instellingen`, `bw_rangen`, `bw_gemeenten`,
`bw_interventies`, `bw_users`, `bw_aanwezigheidslijsten` en `bw_aanwezigen`.
Neem ook lege tabellen mee in je dump. Andere ondersteunde `bw_*`-tabellen zijn
optioneel; foutmeldingen en voertuigen volgen zoals voorheen de aanwezigheid van
de betreffende brontabellen.

Dit is geen algemene SQL-engine. Functies in VALUES, upserts/INSERT IGNORE,
REPLACE/UPDATE/DELETE, LOAD DATA en dumps met DELIMITER/routines worden geweigerd
met een regelnummer. Een normale tabel-export heeft die opdrachten niet nodig.
Ondersteuningsfouten of een onvolledige dump worden gemeld voordat doelrecords
worden geschreven. In foutmeldingen van de parser worden geen rijwaarden getoond.

## Uitgevoerde verificatie

- PHP-syntax gecontroleerd op PHP 8.4.25.
- De echte Symfony 8.1.9-productiecontainer slaagt voor `lint:container`.
- De echte consolehelp toont de nieuwe bron- en controleopties.
- 36 regressiescenario's slagen met Doctrine DBAL 4.5 en ORM 3.7.5 op een
  geïsoleerde SQLite-database met foreign keys en de werkelijke entiteitsmapping.
- De controles omvatten volledige omzettingen, een mislukte vervanging met
  rollback, behoud van andere afdelingen, controle zonder wijzigingen, meer dan
  duizend geschreven tikkingen en een uitgebreide INSERT met 50.000 bronrijen.

De regressies zijn lokaal opnieuw uit te voeren met:

```bash
php tools/sql-dump-regressie.php
```

Alleen die regressietest vereist `pdo_sqlite`; hij gebruikt uitsluitend een
database in geheugen en fictieve gegevens. De productiedumpimport gebruikt je
bestaande MySQL-verbinding en heeft geen SQLite nodig. De tests zijn niet tegen
jouw Easyhost-server of jouw eigen dump uitgevoerd: die dump was hier niet
beschikbaar. Voer daarom eerst de controlemodus op jouw server uit.

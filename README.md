# BrandweerOnline – Symfony 8.1

Herbouw van het oude tik- en aanwezigheidssysteem (PHP 5, `mysql_*`) van Brandweerzone Rivierenland
naar **Symfony 8.1.9** op **PHP 8.4**, met Doctrine ORM 3 en een nieuwe, propere database.

De drie aparte installaties (`project2` = Post Boom/Rumst, `niel` = Post Niel, `jeugd` = Jeugdbrandweer)
zijn nu **één applicatie met drie afdelingen**. De startpagina vervangt de oude `index.php` met de drie knoppen.

## Vereisten

- **PHP 8.4** of hoger (Symfony 8 werkt niet op de huidige PHP 8.2 van de server) met de extensies
  `intl`, `pdo_mysql`, `mbstring`, `zip`, `gd`, `xml`, `ctype`, `iconv`
- MariaDB 10.6+ (zoals nu) of MySQL 8
- Composer 2
- Webserver met document root op `public/` (Apache met `mod_rewrite` of nginx + PHP-FPM)

## Installatie

```bash
composer install --no-dev --optimize-autoloader
cp .env .env.local          # en vul in (zie hieronder)
php bin/console doctrine:migrations:migrate
php bin/console cache:clear --env=prod
```

In `.env.local` (nooit in git):

```dotenv
APP_ENV=prod
APP_SECRET=<lange willekeurige tekst, bv. uit: php -r "echo bin2hex(random_bytes(32));">
APP_BASE_URL=https://www.brandweeronline.com
DATABASE_URL="mysql://GEBRUIKER:WACHTWOORD@127.0.0.1:3306/brandweeronline?charset=utf8mb4"
DATABASE_SERVER_VERSION=10.6.0-MariaDB
MAILER_DSN=smtp://gebruiker:wachtwoord@smtp.example.org:587
MAILER_FROM=noreply@brandweeronline.com
```

Maak de nieuwe database aan met `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`.
De oude databases blijven onaangeroerd; de import leest ze enkel.

## Oude gegevens importeren

De migratie maakt de tabellen aan en vult de 15 posten van de zone in. Daarna importeer je elke oude database:

```bash
php bin/console app:import-legacy boom-rumst "mysql://USER:PASS@127.0.0.1/brandw_project" \
    --naam="Post Boom/Rumst" --volgorde=1 --tikpc=1000:Boom:1 --tikpc=2000:Rumst:5 --no-debug

php bin/console app:import-legacy niel "mysql://USER:PASS@127.0.0.1/brandw_niel" \
    --naam="Post Niel" --volgorde=2 --no-debug

php bin/console app:import-legacy jeugd "mysql://USER:PASS@127.0.0.1/brandw_jeugd" \
    --naam="Jeugdbrandweer" --volgorde=3 --tikpc=1000:Boom:1 --tikpc=2000:Rumst --no-debug
```

- `--tikpc=LEVEL:POST[:PELOTON]` koppelt het tik-pc-account (oud level 1000/2000) aan een post en eventueel
  een vast peloton. De oude code zette lijsten van Boom altijd op het 1e en die van Rumst op het 5e peloton.
- Met `--vervang` wordt een eerder geïmporteerde afdeling eerst gewist (handig bij een proefmigratie).
- Getest op de dump van 21/10/2026: Boom/Rumst (32.927 lijsten, 149.827 tikkingen) in ±13 s,
  Niel (6.159 / 18.200) en Jeugd (326 / 5.296).

Wat de import doet:

| Oud | Nieuw |
|---|---|
| `bw_users` | `persoon` – codes blijven werken (md5 wordt bij de eerste login of tikking automatisch bcrypt) |
| `level` | rol: 1–49 lid, ≥50 foutmeldingen, ≥2200 lijsten, ≥2250 personeel, ≥2300 archief, ≥5000 beheer, 10000 admin, 1000/2000 tik-pc |
| `ploeg` 0/99 | peloton „niet ingedeeld” |
| `bw_aanwezigheidslijsten` | `aanwezigheidslijst` – het oude id blijft het lijstnummer (#32927), `pid` het volgnummer |
| `bw_aanwezigen` | `tikking` |
| `bw_mailing` + `bw_maillist` | `mailinglijst` – „WachtN” → peloton N, „Fouten” → foutmeldingen, Jeugd → alle lijsten |
| `bw_fouten` + `bw_foutberichten` | `foutmelding` + `foutbericht` |
| `bw_voertuigen`, `bw_matteriaal`, `bw_reminders`, `bw_gemeenten`, `bw_interventies`, `bw_rangen`, `bw_instellingen` | idem, per afdeling |

Opkuis tijdens de import:
- Tekst die de oude code met `htmlspecialchars()` opsloeg (`&amp;`) en HTML in foutmeldingen wordt gewone tekst.
- De oude toepassing schreef UTF-8 in latin1-kolommen; namen als „René” komen nu correct over.
- Datums `0000-00-00` worden leeg.
- In Jeugd waren 10 personen gewist terwijl hun 813 tikkingen bleven staan; daarvoor wordt een inactieve
  persoon „Onbekend (tiknummer …)” aangemaakt zodat de historiek bewaard blijft.

`brandw_bwz` (de aanzet tot een zonebreed systeem) wordt niet geïmporteerd; enkel de adressen van de posten zijn overgenomen.

## Nieuwe afdeling zonder oude gegevens

```bash
php bin/console app:afdeling:nieuw mijn-post "Post Voorbeeld"
```

## Modules

| Functie | Wie | Oud bestand |
|---|---|---|
| Tik-pc: lijst maken, intikken, 2e beginuur, tikking wissen met eigen code, afsluiten + mail + afdruk | tik-pc, admin | `aanwezigheid.php`, `aanwezigheden(2).php`, `afdruk.php` |
| Aanwezigheidslijsten: zoeken, bekijken, bewerken, tikking manueel toevoegen/wissen | lijsten | `lijsten.php` |
| Openen/sluiten, archief | archief | `lijsten.php` |
| Prestaties + export naar Excel (.xlsx) | iedereen (eigen), personeel (iedereen) | `mystats.php`, `report.php`, `export.php` |
| Personeel (nooit wissen, enkel inactief) | personeel | `leden.php` |
| Foutmeldingen met dezelfde statussen en mailregels | iedereen; opvolgen ≥ foutmeldingen | `foutmeldingen.php` |
| Statistieken (+ jaaroverzicht per peloton) | iedereen | `stats.php`, `nstats.php`, `chart.php` |
| Mailinglijsten, instellingen, tik-pc-accounts | beheer | `mailing.php`, `instellingen.php` |
| Gemeenten, soorten interventie, controlevragen, voertuigen, materiaal | beheer | (vroeger enkel via phpMyAdmin) |
| Eigen code wijzigen | iedereen | nieuw |

Bewust niet overgenomen:
- **Licenties per toestel** (`licence.php`): stond in de oude code al uitgeschakeld (`verifylicence()` gaf altijd 1).
  De tik-pc meldt zich nu aan met een eigen account en blijft aangemeld (sessieduur 0).
- `reg.php` (uitgeschakeld), `admin.php` (testpagina), `pdf.php`, `fout_ext.php`, `test.php` (`phpinfo()`), `gesloten.php`.

## Beveiliging (t.o.v. de oude toepassing)

- Geen SQL-injectie meer: alles via Doctrine met parameters (de oude code plakte `$_GET`/`$_POST` in queries).
- Codes met bcrypt i.p.v. ongezouten md5; oude codes worden bij gebruik automatisch omgezet.
- Max. 5 foute aanmeldpogingen per 15 minuten (een 4-cijferige code is anders snel geraden).
- CSRF-bescherming op alle formulieren en acties, uitvoer altijd ge-escaped (geen XSS).
- Geen wachtwoorden meer in de code; alles via `.env.local`.
- Groepsmails gaan in BCC i.p.v. alle adressen zichtbaar in „Aan”.
- Afdelingen zijn strikt gescheiden: een gebruiker ziet enkel gegevens van zijn eigen afdeling.
- Geen externe CDN's of advertentiescripts: Bootstrap, iconen, Tom Select en Chart.js zitten in `public/vendor/`.

Aanbevolen na de overstap: laat iedereen een code van 6 cijfers of meer kiezen (*Code wijzigen* in het menu).

## Ontwikkelen en testen

```bash
composer install
php -S 127.0.0.1:8000 -t public          # of: symfony serve
php vendor/bin/phpunit                   # vereist een testdatabase (DATABASE_URL in .env.test.local, achtervoegsel _test)
```

Structuur: `src/Entity` (datamodel), `src/Controller`, `src/Form`, `src/Service` (tikken, lijsten, foutmeldingen, mail),
`src/Security` (login per afdeling, md5-migratie, sessietimeout), `src/Import` (migratie van de oude databases),
`templates/` (Twig + Bootstrap 5).

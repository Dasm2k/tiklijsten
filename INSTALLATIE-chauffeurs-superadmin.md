> Actuele installatie-instructies voor deze pakketversie: `INSTALLATIE-intikken-leeg-focus.md`. Deze update vereist geen nieuwe migratie.

# Update Tikkingen – chauffeurs en superadmin (23 oktober 2026)

## Installeren in je bestaande project

Maak eerst een databaseback-up. Het pakket bevat de bijgewerkte broncode en publieke assets, zonder vendor, lokale instellingen, caches of sessies. De bovenste map in het archief heet `tiklijst.brandweerboom.be`.

Voer vanuit de bestaande projectmap uit (pas de locatie van het tarbestand aan):

```bash
tar -xf /pad/naar/tiklijst.brandweerboom.be.tar --strip-components=1
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/console cache:clear
```

Er zijn geen nieuwe Composer-afhankelijkheden nodig. Bestaande `.env`-bestanden en `.ddev`-instellingen worden niet overschreven. Voor productie gebruik je dezelfde broncode en migratie; voer daar `php bin/console doctrine:migrations:migrate --env=prod --no-interaction` en `php bin/console cache:clear --env=prod` uit. De migratie is voor de bestaande MySQL/MariaDB-database. Voer geen nieuwe legacy-import en geen `doctrine:schema:update --force` uit.

## Chauffeursregistratie aan- of uitzetten

Open **Beheer → Instellingen & accounts** in de gewenste afdeling.

- Met **Chauffeursregistratie actief** stel je de standaard in voor de hele afdeling. Bewaar met **Opslaan**.
- In **Chauffeursregistratie per post** kun je voor elke post kiezen: **Volg afdelingsinstelling**, **Aan** of **Uit**. Bewaar deze afwijkingen met **Chauffeursinstellingen opslaan**.
- De post van de tiklijst bepaalt welke instelling geldt. Een lijst zonder post volgt de afdelingsinstelling.
- **Aan** toont de chauffeurkeuze. Bij afsluiten is minstens één chauffeur vereist als die verplichting ook voor het gekozen interventietype aanstaat.
- **Uit** verbergt de chauffeurkeuze en laat afsluiten zonder chauffeur toe, ook via de beheeracties. Eerdere chauffeurvermeldingen blijven zichtbaar.
- Een postafwijking geldt uitsluitend binnen de geselecteerde afdeling. Zo kan fysieke post Boom de functie aan hebben in Boom/Rumst en uit in Jeugdbrandweer.

De nieuwe migratie `Version20261023100000` zet de afdelingsstandaard uit voor bestaande afdelingen met `jeugd` in de slug of `jeugdbrandweer` in naam/titel. Andere afdelingen starten met de functie aan. De opties zijn daarna vrij aanpasbaar. Ook als de vorige update al geïnstalleerd is, moet je `doctrine:migrations:migrate` opnieuw uitvoeren voor deze aanvullende migratie.

## Chauffeurs instellen en registreren

1. Open Beheer → Voertuigen. Bewerk de bestaande voertuigen en kies hun werkelijke post. Bestaande voertuigen krijgen geen automatische post: Boom en Rumst delen immers een afdeling.
2. Laat `Actief` aangevinkt voor inzetbare voertuigen. Vink het uit bij voertuigen die niet meer aangeboden mogen worden voor chauffeursregistratie.
3. Controleer bij de personeelsleden dat hun post juist is ingevuld.
4. Klik op een ingetikt personeelslid in een open tiklijst. Kies in de pop-up het bestuurde voertuig en druk op Opslaan.
5. Alleen actieve voertuigen uit dezelfde afdeling én dezelfde post als het personeelslid zijn beschikbaar. Iemand zonder post kan geen voertuig toegewezen krijgen.
6. `Geen chauffeur` wist de aanduiding. `Huidige registratie behouden` bewaart de bestaande aanduiding, ook als het voertuig intussen inactief werd.
7. Als chauffeursregistratie aanstaat voor de post van de lijst én het interventietype een chauffeur vereist, kan de lijst alleen afgesloten worden als minstens één van de geregistreerde aanwezigen als chauffeur aangeduid is. De knop blijft anders uitgeschakeld en de server weigert ook een rechtstreeks verzoek. Dezelfde controle geldt voor sluiten vanuit beheer en voor het archiveren van een nog open lijst. Oude, al afgesloten lijsten worden hierdoor niet automatisch gewijzigd.

Per persoon kan één voertuig per lijst geregistreerd worden. Meerdere personen kunnen hetzelfde voertuig aanduiden (bijvoorbeeld bij wisselen van chauffeur). Na afsluiten kan de chauffeurkeuze niet gewijzigd worden; een bevoegde beheerder kan de lijst eerst heropenen.

De vermelding `Chf: voertuignaam` verschijnt achter de naam op de opgeslagen lijst, afdruk/PDF via de browser en in de afsluitmail. De voertuigtekst is een momentopname: later hernoemen of deactiveren wijzigt oude vermeldingen niet. Voertuigen kunnen niet meer via Beheer verwijderd worden; historische verwijzingen worden ook door een database-relatie beschermd.

## Globale superadmin voor Dave

Maak jouw afzonderlijke systeemaccount aan met:

```bash
ddev exec php bin/console app:superadmin:instellen boom-rumst
```

`boom-rumst` is een bestaande afdelingsslug als technische thuisafdeling; je kunt daarna alle afdelingen kiezen. Een normaal personeelsaccount met hetzelfde e-mailadres blijft afzonderlijk bestaan.

Het commando:

- gebruikt de naam Dave Smolderen en `dave.smolderen@bwzr.be`;
- vraagt een persoonlijk wachtwoord (minimaal 14 tekens, maximaal 72 bytes), zonder het te tonen;
- toont een nieuwe persoonlijke TOTP-sleutel die je handmatig toevoegt aan Microsoft Authenticator, Google Authenticator of een andere authenticator;
- vraagt de code uit de app en slaat pas na correcte bevestiging het account op;
- geeft de rol Superadministrator, systeemaccountstatus en een inactiviteitstermijn van 15 minuten.

Gebruik bij handmatig toevoegen: tijdgebonden/TOTP, SHA-1, 6 cijfers, 30 seconden. Bewaar de sleutel veilig; zet deze niet in een e-mail of dit project. Er staat geen standaardwachtwoord of persoonlijke 2FA-sleutel in het pakket.

Wacht op een nieuwe code na het instellen. Meld lokaal aan via:

`https://tikkingen.ddev.site/superadmin/login`

In productie is het pad `/superadmin/login` op je eigen domein. Aanmelden gebeurt met e-mail, wachtwoord en authenticatorcode samen. Hergebruik van een reeds gebruikte code wordt geweigerd. De bestaande beperking van aanmeldpogingen geldt ook voor deze route. De gewone tiknummer/code-login accepteert het superadminaccount niet.

Kies na aanmelden een afdeling. Via het gebruikersmenu → Afdeling wisselen kun je steeds een andere afdeling beheren. Dit wijzigt de afdelingskoppeling in jouw account niet. Een gewone administrator kan geen superadminrol toekennen of jouw systeemaccount aanpassen.

Wachtwoord wijzigen of authenticator verloren? Voer `app:superadmin:instellen boom-rumst` opnieuw uit via de vertrouwde serverconsole. Daarmee vervang je wachtwoord en 2FA-sleutel. De normale pagina voor een numerieke code wijzigen is voor de superadmin geblokkeerd.

## Jeugdbrandweer weer toegankelijk maken

De getoonde melding komt van `PersoonChecker`: het betrokken account heeft `actief = false`. De legacy-import neemt de actiefstatus uit de bron over. Zonder de actuele database kan niet vastgesteld worden welk account het precies is, of wanneer/waarom het inactief gemaakt werd. De oude herstelopdracht voor administratorcodes zoekt alleen actieve administrators en kon dit dus niet oplossen.

Met de nieuwe superadmin:

1. Kies de afdeling Jeugdbrandweer.
2. Open Beheer → Instellingen & accounts.
3. Open het betrokken systeemaccount, vink `Account actief` aan en sla op. Stel zo nodig een nieuwe code in.

De status Actief/INACTIEF is nu zichtbaar in het accountoverzicht.

Als het account geen systeemaccount blijkt te zijn: toon in Personeel ook de inactieve leden en activeer daar het bedoelde lid. Of gebruik de console, zonder enig ander account aan te passen:

```bash
ddev exec php bin/console app:account:activeren <afdelingsslug>
```

Het commando toont de echte accounts, rollen en actiefstatussen van de gekozen afdeling. Selecteer het juiste account. De bestaande code blijft behouden. Gebruik de werkelijke slug uit de bestaande login-URL van Jeugdbrandweer; die wordt niet geraden.

## Uitgevoerde controles

- Alle PHP-broncode op syntax gecontroleerd.
- Symfony-servicecontainer, alle 37 Twig-templates en alle 16 YAML-configuraties gecontroleerd.
- Volledige testsuite: 29 tests, 156 assertions, geslaagd op PHP 8.4.25 / Symfony 8.1 met een geïsoleerde SQLite-testdatabase.
- Inclusief volledige tikflow met afsluitmail, juiste/verkeerde/inactieve voertuigkeuze, CSRF, historiek na hernoemen/deactiveren, afdruk, blokkering na afsluiten, verplichte chauffeur, accountactivering, afdelingswissel, verplichte 2FA, codehergebruik en weigering van superadmin via gewone login.
- Extra getest: uitgeschakelde post sluit zonder chauffeur, een eerder geopend chauffeurformulier wordt geweigerd na uitschakelen, beheerinstellingen worden opgeslagen, postafwijkingen werken en Jeugdbrandweer volgt haar eigen instelling op dezelfde fysieke post.
- Geen toegang tot je lokale DDEV- of productiedatabase. De MySQL/MariaDB-migratie en persoonlijke accountconfiguratie moeten nog in jouw omgeving uitgevoerd worden.

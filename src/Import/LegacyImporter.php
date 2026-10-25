<?php

namespace App\Import;

use App\Enum\FoutStatus;
use App\Enum\FoutType;
use App\Enum\Rol;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zet één database van de oude PHP 5-toepassing (brandw_project, brandw_niel, brandw_jeugd)
 * over naar een afdeling in de nieuwe database. Het bronschema wordt enkel gelezen.
 */
final class LegacyImporter
{
    private LegacyBron $bron;
    private Connection $doel;
    /** @var callable(string): void */
    private $log;

    /** @var array<string, int|null> */
    private array $posten = [];
    /** @var array<int, int> */
    private array $rangen = [];
    /** @var array<int, int> */
    private array $gemeenten = [];
    /** @var array<int, int> */
    private array $types = [];
    /** @var array<int, int> legacy bw_users.id -> persoon.id */
    private array $persoonPerId = [];
    /** @var array<int, int> legacy bw_users.nummer -> persoon.id */
    private array $persoonPerNummer = [];
    /** @var array<int, int> */
    private array $lijsten = [];
    private int $afdelingId = 0;
    private int $onbekend = 0;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->doel = $em->getConnection();
    }

    /**
     * @param array<int, array{post: string, peloton: ?int}> $tikPcs legacy level => instellingen
     * @return array<string, int> tellers
     */
    public function importeer(string $bron, string $slug, string $naam, int $volgorde, array $tikPcs, bool $vervang, callable $log, bool $controleer = false, ?string $bronDatabase = null): array
    {
        $this->log = $log;
        $this->posten = $this->rangen = $this->gemeenten = $this->types = $this->persoonPerId = $this->persoonPerNummer = $this->lijsten = [];
        $this->afdelingId = $this->onbekend = 0;
        ($this->log)('Bron openen en controleren...');
        if (preg_match('~^(mysql|mariadb)://~i', $bron)) {
            if (null !== $bronDatabase) { throw new \InvalidArgumentException('--bron-database is alleen bedoeld voor een SQL-dump.'); }
            $this->bron = new LegacyMysqlBron($bron);
        } else {
            $this->bron = new LegacySqlDumpBron($bron, $bronDatabase);
        }
        try {
            return $this->uitvoeren($slug, $naam, $volgorde, $tikPcs, $vervang, $controleer);
        } finally {
            $this->bron->sluiten();
        }
    }

    private function uitvoeren(string $slug, string $naam, int $volgorde, array $tikPcs, bool $vervang, bool $controleer): array
    {
        $bronAantallen = $this->controleerBron();
        $this->controleerDoel();

        $bestaand = $this->doel->fetchOne('SELECT id FROM afdeling WHERE slug = ?', [$slug]);
        if ($bestaand && !$vervang) {
            throw new \RuntimeException(sprintf('Afdeling "%s" bestaat al. Gebruik --vervang om ze opnieuw te importeren.', $slug));
        }
        if ($controleer) {
            ($this->log)('Controle voltooid; er zijn geen doelgegevens gewijzigd.');
            return $bronAantallen;
        }

        $tellers = [];
        $this->doel->beginTransaction();
        try {
            if ($bestaand) {
                $this->verwijderAfdeling((int) $bestaand);
                ($this->log)('Bestaande afdeling verwijderd.');
            }
            $this->laadPosten();
            $tellers['rangen'] = $this->rangen();
            $afdelingId = $this->afdelingId = $this->afdeling($slug, $naam, $volgorde);
            $tellers['gemeenten'] = $this->gemeenten($afdelingId);
            $tellers['interventietypes'] = $this->interventieTypes($afdelingId);
            $tellers['personen'] = $this->personen($afdelingId, $tikPcs);
            $tellers['lijsten'] = $this->lijsten($afdelingId);
            [$tellers['tikkingen'], $tellers['tikkingen_overgeslagen']] = $this->tikkingen();
            $tellers['onbekende_personen_aangemaakt'] = $this->onbekend;
            $tellers['mailinglijsten'] = $this->mailinglijsten($afdelingId);
            $tellers['voertuigen'] = $this->eenvoudig('bw_voertuigen', 'voertuig', $afdelingId, fn (array $r) => [
                'naam' => self::tekst($r['naam']), 'roepnaam' => self::tekst($r['roepnaam']), 'nummerplaat' => self::tekst($r['plaat']), 'keuring' => self::datum($r['keuring']),
            ]);
            $tellers['materiaal'] = $this->eenvoudig('bw_matteriaal', 'materiaal', $afdelingId, fn (array $r) => [
                'naam' => self::tekst($r['naam']), 'keuring' => self::datum($r['keuring']),
            ]);
            $tellers['herinneringen'] = $this->eenvoudig('bw_reminders', 'herinnering', $afdelingId, fn (array $r) => ['vraag' => self::tekst($r['vraag'])]);
            [$tellers['foutmeldingen'], $tellers['foutberichten']] = $this->fouten($afdelingId);

            $this->doel->update('afdeling', [
                'foutmeldingen_actief' => (int) $this->heeftTabel('bw_fouten'),
                'voertuigen_actief' => (int) $this->heeftTabel('bw_voertuigen'),
            ], ['id' => $afdelingId]);

            $this->doel->commit();
        } catch (\Throwable $e) {
            $this->doel->rollBack();
            ($this->log)('<error>Import teruggedraaid; er zijn geen importgegevens opgeslagen.</error>');
            throw $e;
        }
        return $tellers;
    }

    private function verwijderAfdeling(int $id): void
    {
        if ($this->doel->createSchemaManager()->tablesExist(['handeling'])) {
            throw new \RuntimeException('Een bestaande afdeling overschrijven is geblokkeerd om de historiek te bewaren. Importeer onder een nieuwe afdelingsslug.');
        }
        // Volgorde respecteert de foreign keys (ook als ON DELETE CASCADE niet zou bestaan).
        $this->doel->executeStatement('DELETE FROM foutbericht WHERE foutmelding_id IN (SELECT id FROM foutmelding WHERE afdeling_id = ?)', [$id]);
        $this->doel->executeStatement('DELETE FROM foutmelding WHERE afdeling_id = ?', [$id]);
        $this->doel->executeStatement('DELETE FROM tikking WHERE lijst_id IN (SELECT id FROM aanwezigheidslijst WHERE afdeling_id = ?)', [$id]);
        $this->doel->executeStatement('DELETE FROM aanwezigheidslijst WHERE afdeling_id = ?', [$id]);
        $this->doel->executeStatement('DELETE FROM mailinglijst_lid WHERE mailinglijst_id IN (SELECT id FROM mailinglijst WHERE afdeling_id = ?)', [$id]);
        foreach (['mailinglijst', 'persoon', 'gemeente', 'interventie_type', 'voertuig', 'materiaal', 'herinnering'] as $tabel) {
            $this->doel->executeStatement("DELETE FROM $tabel WHERE afdeling_id = ?", [$id]);
        }
        $this->doel->executeStatement('DELETE FROM afdeling WHERE id = ?', [$id]);
    }

    private function heeftTabel(string $tabel): bool
    {
        return $this->bron->heeftTabel($tabel);
    }

    /** @return iterable<array<string, mixed>> */
    private function rijen(string $tabel): iterable
    {
        return $this->bron->rijen($tabel);
    }

    /** Controleert de volledige bron voordat ook maar één doelrecord wordt geschreven. */
    private function controleerBron(): array
    {
        if ($this->bron instanceof LegacySqlDumpBron) {
            foreach ($this->bron->waarschuwingen() as $waarschuwing) {
                ($this->log)('<comment>'.$waarschuwing.'</comment>');
            }
        }
        $vereist = [
            'bw_instellingen' => [],
            'bw_rangen' => ['rang', 'kort', 'naam'],
            'bw_gemeenten' => ['id', 'naam'],
            'bw_interventies' => ['id', 'naam'],
            'bw_users' => ['id', 'nummer', 'code', 'level', 'ploeg', 'rang', 'naam', 'voornaam', 'email', 'actief'],
            'bw_aanwezigheidslijsten' => ['id', 'datum', 'uur', 'post', 'gemeente', 'interventie', 'info', 'ploeg', 'bevel', 'afgesloten', 'archief'],
            'bw_aanwezigen' => ['id', 'interventie', 'user', 'datum', 'uur', 'begindatum', 'beginuur'],
        ];
        $optioneel = [
            'bw_post' => ['id', 'naam'],
            'bw_mailing' => ['id', 'naam', 'omschrijving'], 'bw_maillist' => ['mailing', 'user'],
            'bw_voertuigen' => ['id', 'naam', 'roepnaam', 'plaat', 'keuring'],
            'bw_matteriaal' => ['id', 'naam', 'keuring'], 'bw_reminders' => ['id', 'vraag'],
            'bw_fouten' => ['id', 'type', 'wat', 'probleem', 'melder', 'opvolger', 'datum', 'einde', 'status', 'mailall'],
            'bw_foutberichten' => ['id', 'fout', 'datum', 'status', 'user', 'bericht'],
        ];
        $aantallen = [];
        foreach ($vereist + $optioneel as $tabel => $kolommen) {
            if (!$this->heeftTabel($tabel)) {
                if (isset($vereist[$tabel])) { throw new \RuntimeException('Vereiste brontabel ontbreekt: '.$tabel.'. Gebruik een volledige dump inclusief lege tabellen.'); }
                continue;
            }
            $aantal = 0;
            foreach ($this->rijen($tabel) as $rij) {
                $ontbreekt = array_diff($kolommen, array_keys($rij));
                if ($ontbreekt) { throw new \RuntimeException('Ontbrekende kolommen in '.$tabel.': '.implode(', ', $ontbreekt).'.'); }
                ++$aantal;
            }
            $aantallen[$tabel] = $aantal;
            ($this->log)(sprintf('  %s: %d rijen', $tabel, $aantal));
        }
        return $aantallen;
    }

    private function controleerDoel(): void
    {
        $tabellen = ['afdeling', 'post', 'rang', 'gemeente', 'interventie_type', 'persoon', 'aanwezigheidslijst', 'tikking', 'mailinglijst', 'mailinglijst_lid', 'voertuig', 'materiaal', 'herinnering', 'foutmelding', 'foutbericht'];
        $ontbreekt = array_diff($tabellen, $this->doel->createSchemaManager()->listTableNames());
        if ($ontbreekt) { throw new \RuntimeException('Symfony-tabellen ontbreken in de doeldatabase: '.implode(', ', $ontbreekt).'. Voer eerst de bestaande applicatiemigraties uit.'); }
        if ($this->doel->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            foreach ($this->doel->fetchAllAssociative('SELECT TABLE_NAME AS tabel, ENGINE AS engine FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()') as $rij) {
                if (in_array($rij['tabel'], $tabellen, true) && 'InnoDB' !== $rij['engine']) {
                    throw new \RuntimeException('Doeltabel '.$rij['tabel'].' moet InnoDB gebruiken om een mislukte import te kunnen terugdraaien.');
                }
            }
        }
    }

    private function laadPosten(): void
    {
        foreach ($this->doel->fetchAllAssociative('SELECT id, code, naam, gemeente FROM post') as $p) {
            $this->posten[self::sleutel($p['naam'])] = (int) $p['id'];
            $this->posten[self::sleutel($p['code'])] = (int) $p['id'];
            if ($p['gemeente']) {
                $this->posten[self::sleutel($p['gemeente'])] ??= (int) $p['id'];
            }
        }
    }

    private function postId(?string $naam): ?int
    {
        return null === $naam || '' === trim($naam) ? null : ($this->posten[self::sleutel($naam)] ?? null);
    }

    private function rangen(): int
    {
        foreach ($this->rijen('bw_rangen') as $r) {
            $niveau = (int) $r['rang'];
            $id = $this->doel->fetchOne('SELECT id FROM rang WHERE niveau = ?', [$niveau]);
            if (!$id) {
                $this->doel->insert('rang', ['niveau' => $niveau, 'kort' => self::tekst($r['kort']), 'naam' => self::tekst($r['naam'])]);
                $id = $this->doel->lastInsertId();
            }
            $this->rangen[$niveau] = (int) $id;
        }
        return \count($this->rangen);
    }

    private function afdeling(string $slug, string $naam, int $volgorde): int
    {
        $inst = [];
        foreach ($this->rijen('bw_instellingen') as $rij) { $inst = $rij; break; }
        // Aantal pelotons volgens de indeling van het personeel (oude lijsten met een hoger peloton blijven gewoon bewaard).
        $maxPeloton = 1;
        foreach ($this->rijen('bw_users') as $u) {
            $peloton = (int) $u['ploeg'];
            if ($peloton < 99) { $maxPeloton = max($maxPeloton, $peloton); }
        }
        $this->doel->insert('afdeling', [
            'slug' => $slug,
            'naam' => $naam,
            'titel' => self::tekst($inst['site_title'] ?? $naam) ?: $naam,
            'footer' => self::tekst($inst['site_footer'] ?? ''),
            'sessieduur' => max(0, (int) ($inst['sessie_interval'] ?? 0)),
            'aantal_pelotons' => min(9, $maxPeloton),
            'foutmeldingen_actief' => 0,
            'voertuigen_actief' => 0,
            'kadaver_ontvangers' => null,
            'volgorde' => $volgorde,
        ]);
        $id = (int) $this->doel->lastInsertId();
        ($this->log)(sprintf('Afdeling "%s" aangemaakt (id %d, %d pelotons).', $naam, $id, min(9, $maxPeloton)));
        return $id;
    }

    private function gemeenten(int $afdeling): int
    {
        foreach ($this->rijen('bw_gemeenten') as $r) {
            $this->doel->insert('gemeente', ['afdeling_id' => $afdeling, 'naam' => self::tekst($r['naam']), 'actief' => 1]);
            $this->gemeenten[(int) $r['id']] = (int) $this->doel->lastInsertId();
        }
        return \count($this->gemeenten);
    }

    private function interventieTypes(int $afdeling): int
    {
        foreach ($this->rijen('bw_interventies') as $r) {
            $this->doel->insert('interventie_type', [
                'afdeling_id' => $afdeling,
                'naam' => self::tekst($r['naam']),
                'volgorde' => (int) $r['id'],
                'actief' => 1,
                // De oude jaarstatistiek telde enkel interventie-id's < 14.
                'in_statistiek' => (int) ((int) $r['id'] < 14),
            ]);
            $this->types[(int) $r['id']] = (int) $this->doel->lastInsertId();
        }
        return \count($this->types);
    }

    /** @param array<int, array{post: string, peloton: ?int}> $tikPcs */
    private function personen(int $afdeling, array $tikPcs): int
    {
        $postNamen = [];
        if ($this->heeftTabel('bw_post')) {
            foreach ($this->rijen('bw_post') as $p) {
                $postNamen[(int) $p['id']] = $p['naam'];
            }
        }

        foreach ($this->rijen('bw_users') as $u) {
            $level = (int) $u['level'];
            $rol = Rol::vanLegacyLevel($level);
            $systeem = Rol::POST === $rol || Rol::ADMIN === $rol || 9999 === (int) $u['nummer'];
            $postId = $this->postId($postNamen[(int) ($u['post'] ?? 0)] ?? null);
            $vastPeloton = null;
            if (Rol::POST === $rol) {
                $cfg = $tikPcs[$level] ?? null;
                $postId = $this->postId($cfg['post'] ?? $u['naam']) ?? $postId;
                $vastPeloton = $cfg['peloton'] ?? null;
                if (!$postId) {
                    ($this->log)(sprintf('<comment>Let op: geen post gevonden voor tik-pc "%s" (nummer %d). Stel die in via Instellingen.</comment>', $u['naam'], $u['nummer']));
                }
            }
            $peloton = (int) $u['ploeg'];
            $sessie = null !== ($u['sessie_time'] ?? null) && (int) $u['sessie_time'] > 0 ? (int) $u['sessie_time'] : null;
            if (Rol::POST === $rol && null === $sessie) {
                $sessie = 0; // tik-pc blijft aangemeld (zoals vroeger zonder globale timeout)
            }
            $email = trim((string) $u['email']);

            $this->doel->insert('persoon', [
                'afdeling_id' => $afdeling,
                'tiknummer' => (int) $u['nummer'],
                'zonenummer' => (int) ($u['zonenummer'] ?? 0) > 0 ? (int) $u['zonenummer'] : null,
                'code' => (string) $u['code'],
                'rang_id' => $this->rangen[(int) $u['rang']] ?? null,
                'peloton' => $peloton >= 1 && $peloton < 99 ? $peloton : null,
                'post_id' => $postId,
                'naam' => self::tekst($u['naam']),
                'voornaam' => self::tekst($u['voornaam']),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'actief' => (int) ((int) $u['actief'] > 0),
                'beroeps' => (int) ((int) ($u['beroeps'] ?? 0) > 0),
                'rol' => $rol->value,
                'sessieduur' => $sessie,
                'vast_peloton' => $vastPeloton,
                'laatst_gezien' => self::datumTijd($u['seen'] ?? null),
                'systeemaccount' => (int) $systeem,
            ]);
            $id = (int) $this->doel->lastInsertId();
            $this->persoonPerId[(int) $u['id']] = $id;
            $this->persoonPerNummer[(int) $u['nummer']] = $id;
        }
        return \count($this->persoonPerId);
    }

    private function lijsten(int $afdeling): int
    {
        foreach ($this->rijen('bw_aanwezigheidslijsten') as $l) {
            $start = self::datumTijd($l['datum'].' '.$l['uur']) ?? '1970-01-01 00:00:00';
            $tweede = self::datum($l['twdatum'] ?? null) ? self::datumTijd($l['twdatum'].' '.($l['twuur'] ?: '00:00:00')) : null;
            $this->doel->insert('aanwezigheidslijst', [
                'afdeling_id' => $afdeling,
                'nummer' => (int) $l['id'],
                'volgnummer' => (int) ($l['pid'] ?? 0) ?: null,
                'post_id' => $this->postId($l['post']),
                'start' => $start,
                'tweede_start' => $tweede,
                'gemeente_id' => $this->gemeenten[(int) $l['gemeente']] ?? null,
                'interventie_type_id' => $this->types[(int) $l['interventie']] ?? null,
                'info' => mb_substr(self::tekst($l['info']), 0, 100),
                'peloton' => max(1, (int) $l['ploeg']),
                'bevelvoerder_id' => ctype_digit((string) $l['bevel']) ? ($this->persoonPerId[(int) $l['bevel']] ?? null) : null,
                'afgesloten' => (int) ((int) $l['afgesloten'] > 0),
                'gearchiveerd' => (int) ((int) $l['archief'] > 0),
            ]);
            $this->lijsten[(int) $l['id']] = (int) $this->doel->lastInsertId();
        }
        return \count($this->lijsten);
    }

    /** @return array{int, int} */
    private function tikkingen(): array
    {
        $ok = 0;
        $overgeslagen = 0;
        $gezien = [];
        $buffer = [];
        foreach ($this->rijen('bw_aanwezigen') as $a) {
            $lijst = $this->lijsten[(int) $a['interventie']] ?? null;
            $persoon = $this->persoonPerNummer[(int) $a['user']] ?? $this->onbekendePersoon((int) $a['user']);
            $einde = self::datumTijd($a['datum'].' '.$a['uur']);
            if (!$lijst || !$persoon || !$einde || isset($gezien[$lijst.'-'.$persoon])) {
                ++$overgeslagen;
                continue;
            }
            $gezien[$lijst.'-'.$persoon] = true;
            $start = self::datum($a['begindatum']) ? self::datumTijd($a['begindatum'].' '.$a['beginuur']) : null;
            $buffer[] = [$lijst, $persoon, $start, $einde];
            if (\count($buffer) >= 1000) {
                $ok += $this->schrijfTikkingen($buffer);
                $buffer = [];
            }
        }
        $ok += $this->schrijfTikkingen($buffer);
        return [$ok, $overgeslagen];
    }

    /**
     * Sommige personen werden in de oude toepassing echt gewist terwijl hun tikkingen bleven staan.
     * Om die historiek te bewaren maken we een inactieve persoon "Onbekend" aan met hetzelfde tiknummer.
     */
    private function onbekendePersoon(int $nummer): ?int
    {
        if ($nummer <= 0) {
            return null;
        }
        $this->doel->insert('persoon', [
            'afdeling_id' => $this->afdelingId,
            'tiknummer' => $nummer,
            'code' => '!', // geen geldige hash: kan niet aanmelden of tikken
            'naam' => 'Onbekend',
            'voornaam' => '(tiknummer '.$nummer.')',
            'actief' => 0,
            'beroeps' => 0,
            'rol' => Rol::LID->value,
            'systeemaccount' => 0,
        ]);
        ++$this->onbekend;
        return $this->persoonPerNummer[$nummer] = (int) $this->doel->lastInsertId();
    }

    /** @param array<int, array{int, int, ?string, string}> $rijen */
    private function schrijfTikkingen(array $rijen): int
    {
        if (!$rijen) {
            return 0;
        }
        $sql = 'INSERT INTO tikking (lijst_id, persoon_id, start, einde) VALUES '.implode(',', array_fill(0, \count($rijen), '(?, ?, ?, ?)'));
        $this->doel->executeStatement($sql, array_merge(...$rijen));
        return \count($rijen);
    }

    private function mailinglijsten(int $afdeling): int
    {
        if (!$this->heeftTabel('bw_mailing')) { return 0; }
        $aantal = 0;
        $mailingIds = [];
        foreach ($this->rijen('bw_mailing') as $m) {
            $naam = self::tekst($m['naam']);
            $omschrijving = self::tekst($m['omschrijving']);
            $peloton = preg_match('/^wacht\s*(\d)$/i', $naam, $x) ? (int) $x[1] : null;
            $fouten = 7 === (int) $m['id'] || 0 === strcasecmp($naam, 'Fouten');
            $alle = !$peloton && !$fouten && preg_match('/lijst wordt afgesloten/i', $omschrijving);
            $this->doel->insert('mailinglijst', [
                'afdeling_id' => $afdeling,
                'naam' => $naam,
                'omschrijving' => $omschrijving,
                'peloton' => $peloton,
                'alle_lijsten' => (int) $alle,
                'foutmeldingen' => (int) $fouten,
            ]);
            $mailingIds[(int) $m['id']] = (int) $this->doel->lastInsertId();
            ++$aantal;
        }
        $gezien = [];
        if ($this->heeftTabel('bw_maillist')) {
            foreach ($this->rijen('bw_maillist') as $lid) {
                $id = $mailingIds[(int) $lid['mailing']] ?? null;
                $persoon = $this->persoonPerId[(int) $lid['user']] ?? null;
                if (!$id || !$persoon || isset($gezien[$id.'-'.$persoon])) { continue; }
                $gezien[$id.'-'.$persoon] = true;
                $this->doel->insert('mailinglijst_lid', ['mailinglijst_id' => $id, 'persoon_id' => $persoon]);
            }
        }
        return $aantal;
    }

    private function eenvoudig(string $bronTabel, string $doelTabel, int $afdeling, callable $map): int
    {
        if (!$this->heeftTabel($bronTabel)) {
            return 0;
        }
        $aantal = 0;
        foreach ($this->rijen($bronTabel) as $r) {
            $this->doel->insert($doelTabel, ['afdeling_id' => $afdeling] + $map($r));
            ++$aantal;
        }
        return $aantal;
    }

    /** @return array{int, int} */
    private function fouten(int $afdeling): array
    {
        if (!$this->heeftTabel('bw_fouten')) {
            return [0, 0];
        }
        $fouten = 0;
        $berichten = 0;
        $foutIds = [];
        foreach ($this->rijen('bw_fouten') as $f) {
            $this->doel->insert('foutmelding', [
                'afdeling_id' => $afdeling,
                'type' => (FoutType::tryFrom((int) $f['type']) ?? FoutType::ANDERS)->value,
                'wat' => mb_substr(self::tekst($f['wat']), 0, 128),
                'probleem' => self::tekst($f['probleem']),
                'melder_id' => $this->persoonPerNummer[(int) $f['melder']] ?? null,
                'opvolger_id' => $this->persoonPerNummer[(int) $f['opvolger']] ?? null,
                'aangemaakt' => self::datumTijd($f['datum'].' 00:00:00') ?? date('Y-m-d H:i:s'),
                'afgesloten' => self::datum($f['einde']) ? $f['einde'].' 00:00:00' : null,
                'status' => (FoutStatus::tryFrom((int) $f['status']) ?? FoutStatus::NIEUW)->value,
                'iedereen_mailen' => (int) ((int) $f['mailall'] > 0),
            ]);
            $foutIds[(int) $f['id']] = (int) $this->doel->lastInsertId();
            ++$fouten;
        }
        if ($this->heeftTabel('bw_foutberichten')) {
            foreach ($this->rijen('bw_foutberichten') as $b) {
                $id = $foutIds[(int) $b['fout']] ?? null;
                if (null === $id) { continue; }
                $this->doel->insert('foutbericht', [
                    'foutmelding_id' => $id,
                    'datum' => self::datumTijd($b['datum'].' 00:00:00') ?? date('Y-m-d H:i:s'),
                    'status' => (FoutStatus::tryFrom((int) $b['status']) ?? FoutStatus::NIEUW)->value,
                    'auteur_id' => $this->persoonPerNummer[(int) $b['user']] ?? null,
                    'bericht' => self::tekst($b['bericht']),
                ]);
                ++$berichten;
            }
        }
        return [$fouten, $berichten];
    }

    /** De oude toepassing sloeg tekst op met htmlspecialchars() en soms met HTML-opmaak. */
    public static function tekst(mixed $waarde): string
    {
        $s = (string) $waarde;
        if (!mb_check_encoding($s, 'UTF-8')) {
            // Echte latin1/Windows-1252-tekst (bv. ingevoerd via phpMyAdmin).
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        if (str_contains($s, '<')) {
            $s = preg_replace(['~<br\s*/?>~i', '~<li[^>]*>~i', '~</li>~i', '~</(p|ul|div)>~i'], ["\n", "\n- ", '', "\n"], $s);
            $s = strip_tags($s);
        }
        for ($i = 0; $i < 2 && str_contains($s, '&'); ++$i) {
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $s = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $s));
        return trim($s);
    }

    public static function datum(mixed $waarde): ?string
    {
        $s = trim((string) $waarde);
        return '' === $s || str_starts_with($s, '0000-00-00') ? null : substr($s, 0, 10);
    }

    public static function datumTijd(mixed $waarde): ?string
    {
        $s = trim((string) $waarde);
        if ('' === $s || str_starts_with($s, '0000-00-00')) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', strlen($s) === 16 ? $s.':00' : $s);
        return $d ? $d->format('Y-m-d H:i:s') : null;
    }

    private static function sleutel(string $naam): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(self::tekst($naam)));
    }
}

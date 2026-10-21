<?php

namespace App\Import;

use App\Enum\FoutStatus;
use App\Enum\FoutType;
use App\Enum\Rol;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Zet één database van de oude PHP 5-toepassing (brandw_project, brandw_niel, brandw_jeugd)
 * over naar een afdeling in de nieuwe database. Het bronschema wordt enkel gelezen.
 */
final class LegacyImporter
{
    private Connection $bron;
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
    public function importeer(string $bronDsn, string $slug, string $naam, int $volgorde, array $tikPcs, bool $vervang, callable $log): array
    {
        $this->log = $log;
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($bronDsn);
        // De oude toepassing schreef UTF-8 via een latin1-verbinding in latin1-kolommen.
        // Lezen met latin1 geeft de oorspronkelijke bytes terug; tekst() zet ze om naar geldige UTF-8.
        $this->bron = DriverManager::getConnection($params + ['charset' => 'latin1']);

        $bestaand = $this->doel->fetchOne('SELECT id FROM afdeling WHERE slug = ?', [$slug]);
        if ($bestaand && !$vervang) {
            throw new \RuntimeException(sprintf('Afdeling "%s" bestaat al. Gebruik --vervang om ze opnieuw te importeren.', $slug));
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
            throw $e;
        }
        return $tellers;
    }

    private function verwijderAfdeling(int $id): void
    {
        // Volgorde respecteert de foreign keys (ook als ON DELETE CASCADE niet zou bestaan).
        $this->doel->executeStatement('DELETE b FROM foutbericht b JOIN foutmelding f ON f.id = b.foutmelding_id WHERE f.afdeling_id = ?', [$id]);
        $this->doel->executeStatement('DELETE FROM foutmelding WHERE afdeling_id = ?', [$id]);
        $this->doel->executeStatement('DELETE t FROM tikking t JOIN aanwezigheidslijst l ON l.id = t.lijst_id WHERE l.afdeling_id = ?', [$id]);
        $this->doel->executeStatement('DELETE FROM aanwezigheidslijst WHERE afdeling_id = ?', [$id]);
        $this->doel->executeStatement('DELETE ml FROM mailinglijst_lid ml JOIN mailinglijst m ON m.id = ml.mailinglijst_id WHERE m.afdeling_id = ?', [$id]);
        foreach (['mailinglijst', 'persoon', 'gemeente', 'interventie_type', 'voertuig', 'materiaal', 'herinnering'] as $tabel) {
            $this->doel->executeStatement("DELETE FROM $tabel WHERE afdeling_id = ?", [$id]);
        }
        $this->doel->executeStatement('DELETE FROM afdeling WHERE id = ?', [$id]);
    }

    private function heeftTabel(string $tabel): bool
    {
        return (bool) $this->bron->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$tabel]);
    }

    /** @return array<int, array<string, mixed>> */
    private function rijen(string $sql): array
    {
        return $this->bron->fetchAllAssociative($sql);
    }

    private function laadPosten(): void
    {
        foreach ($this->doel->fetchAllAssociative('SELECT id, naam, gemeente FROM post') as $p) {
            $this->posten[self::sleutel($p['naam'])] = (int) $p['id'];
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
        foreach ($this->rijen('SELECT rang, kort, naam FROM bw_rangen') as $r) {
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
        $inst = $this->bron->fetchAssociative('SELECT * FROM bw_instellingen ORDER BY id LIMIT 1') ?: [];
        // Aantal pelotons volgens de indeling van het personeel (oude lijsten met een hoger peloton blijven gewoon bewaard).
        $maxPeloton = max((int) $this->bron->fetchOne('SELECT MAX(ploeg) FROM bw_users WHERE ploeg < 99'), 1);
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
        foreach ($this->rijen('SELECT id, naam FROM bw_gemeenten') as $r) {
            $this->doel->insert('gemeente', ['afdeling_id' => $afdeling, 'naam' => self::tekst($r['naam']), 'actief' => 1]);
            $this->gemeenten[(int) $r['id']] = (int) $this->doel->lastInsertId();
        }
        return \count($this->gemeenten);
    }

    private function interventieTypes(int $afdeling): int
    {
        foreach ($this->rijen('SELECT id, naam FROM bw_interventies ORDER BY id') as $r) {
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
            foreach ($this->rijen('SELECT id, naam FROM bw_post') as $p) {
                $postNamen[(int) $p['id']] = $p['naam'];
            }
        }

        foreach ($this->rijen('SELECT * FROM bw_users ORDER BY id') as $u) {
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
                'zonenummer' => (int) $u['zonenummer'] > 0 ? (int) $u['zonenummer'] : null,
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
        $stmt = $this->bron->executeQuery('SELECT * FROM bw_aanwezigheidslijsten ORDER BY id');
        while ($l = $stmt->fetchAssociative()) {
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
                'centralist_id' => ctype_digit((string) $l['centralist']) ? ($this->persoonPerId[(int) $l['centralist']] ?? null) : null,
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
        $stmt = $this->bron->executeQuery('SELECT * FROM bw_aanwezigen ORDER BY id');
        while ($a = $stmt->fetchAssociative()) {
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
        $aantal = 0;
        foreach ($this->rijen('SELECT * FROM bw_mailing ORDER BY id') as $m) {
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
            $id = (int) $this->doel->lastInsertId();
            $leden = [];
            foreach ($this->bron->fetchFirstColumn('SELECT user FROM bw_maillist WHERE mailing = ?', [$m['id']]) as $user) {
                if ($p = $this->persoonPerId[(int) $user] ?? null) {
                    $leden[$p] = true;
                }
            }
            foreach (array_keys($leden) as $p) {
                $this->doel->insert('mailinglijst_lid', ['mailinglijst_id' => $id, 'persoon_id' => $p]);
            }
            ++$aantal;
        }
        return $aantal;
    }

    private function eenvoudig(string $bronTabel, string $doelTabel, int $afdeling, callable $map): int
    {
        if (!$this->heeftTabel($bronTabel)) {
            return 0;
        }
        $aantal = 0;
        foreach ($this->rijen("SELECT * FROM $bronTabel ORDER BY id") as $r) {
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
        foreach ($this->rijen('SELECT * FROM bw_fouten ORDER BY id') as $f) {
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
            $id = (int) $this->doel->lastInsertId();
            ++$fouten;
            foreach ($this->bron->fetchAllAssociative('SELECT * FROM bw_foutberichten WHERE fout = ? ORDER BY id', [$f['id']]) as $b) {
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

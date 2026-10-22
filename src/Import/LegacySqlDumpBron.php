<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Leest snapshots uit mysqldump/phpMyAdmin; schrijft nooit SQL uit het bestand naar MySQL. */
#[Exclude]
final class LegacySqlDumpBron implements LegacyBron
{
    public const TABELLEN = [
        'bw_instellingen', 'bw_rangen', 'bw_gemeenten', 'bw_interventies', 'bw_users', 'bw_post',
        'bw_aanwezigheidslijsten', 'bw_aanwezigen', 'bw_mailing', 'bw_maillist',
        'bw_voertuigen', 'bw_matteriaal', 'bw_reminders', 'bw_fouten', 'bw_foutberichten',
    ];

    private LegacyDumpTokens $tokens;
    private ?array $wachtend = null;
    private string $database = '';
    private string $geselecteerd = '';
    private ?string $verbindingCharset = null;
    /** @var array<string, array<string, array{kolommen: array, charset: ?string, rijen: LegacyDumpRijen, gedefinieerd: bool}>> */
    private array $tabellen = [];
    /** @var list<string> */
    private array $waarschuwingen = [];

    public function __construct(string $pad, ?string $database = null)
    {
        $this->tokens = new LegacyDumpTokens($pad);
        try {
            $this->leesDump();
            $keuzes = array_keys($this->tabellen);
            if (null !== $database) {
                if (!isset($this->tabellen[$database])) {
                    throw new \RuntimeException('De gekozen brondatabase komt niet in deze dump voor. Laat --bron-database weg bij een dump zonder USE-opdracht.');
                }
                $this->geselecteerd = $database;
            } elseif (1 === count($keuzes)) {
                $this->geselecteerd = $keuzes[0];
            } elseif (!$keuzes) {
                throw new \RuntimeException('Geen bekende bw_-tabellen in de SQL-dump gevonden. Gebruik een volledige SQL-export van de oude toepassing.');
            } else {
                throw new \RuntimeException('De dump bevat meerdere brondatabases. Kies één database met --bron-database. Gevonden: '.implode(', ', array_map(static fn ($naam) => '' === $naam ? '(zonder USE)' : $naam, $keuzes)));
            }
            foreach ($this->tabellen[$this->geselecteerd] as $naam => $tabel) {
                $tabel['rijen']->voorbereiden();
                if (!$tabel['gedefinieerd']) {
                    $this->waarschuwingen[] = $naam.': geen CREATE TABLE gevonden; de kolomtekensets zijn onbekend. Een volledige export geeft de betrouwbaarste tekstomzetting.';
                }
            }
        } catch (\Throwable $e) {
            $this->sluiten();
            throw $e;
        } finally {
            $this->tokens->sluiten();
        }
    }

    public function heeftTabel(string $tabel): bool
    {
        return isset($this->tabellen[$this->geselecteerd][$tabel]);
    }

    public function rijen(string $tabel): iterable
    {
        if (!$this->heeftTabel($tabel)) {
            throw new \RuntimeException('Brontabel ontbreekt in SQL-dump: '.$tabel);
        }
        yield from $this->tabellen[$this->geselecteerd][$tabel]['rijen']->rijen();
    }

    public function aantallen(): array
    {
        $aantallen = [];
        foreach ($this->tabellen[$this->geselecteerd] as $naam => $tabel) {
            $aantallen[$naam] = $tabel['rijen']->aantal();
        }
        return $aantallen;
    }

    public function waarschuwingen(): array { return $this->waarschuwingen; }

    public function sluiten(): void
    {
        foreach ($this->tabellen as $tabellen) {
            foreach ($tabellen as $tabel) { $tabel['rijen']->sluiten(); }
        }
        $this->tabellen = [];
    }

    public function __destruct() { $this->sluiten(); }

    private function leesDump(): void
    {
        while (null !== ($token = $this->volgende())) {
            if ('symbol' === $token[0] && ';' === $token[1]) { continue; }
            if ('word' !== $token[0]) { throw $this->tokens->fout('Onverwacht begin van een SQL-opdracht.'); }
            switch (strtoupper($token[1])) {
                case 'USE':
                    $this->database = $this->identifier();
                    $this->verwacht(';');
                    break;
                case 'CREATE': $this->create(); break;
                case 'INSERT': $this->insert(); break;
                case 'SET':
                    $delen = [];
                    while (null !== ($deel = $this->volgende()) && !('symbol' === $deel[0] && ';' === $deel[1])) {
                        $delen[] = 'string' === $deel[0] ? "'".$deel[1]."'" : $deel[1];
                    }
                    $this->instellingen(implode(' ', $delen));
                    break;
                case 'DELIMITER':
                case 'LOAD':
                case 'REPLACE':
                case 'UPDATE':
                case 'DELETE':
                case 'TRUNCATE':
                case 'RENAME':
                    throw $this->tokens->fout('Deze dump bevat opdrachten voor routines, externe bestanden of gegevenswijzigingen. Exporteer de tabellen als CREATE TABLE en INSERT INTO ... VALUES.');
                default:
                    // DROP/ALTER, locks, transacties en privileges zijn geen brongegevens.
                    $this->totPuntkomma();
            }
        }
    }

    private function create(): void
    {
        $type = strtoupper($this->vereist()[1]);
        if ('TEMPORARY' === $type) { $type = strtoupper($this->vereist()[1]); }
        if (in_array($type, ['TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'DEFINER'], true)) {
            throw $this->tokens->fout('Exporteer zonder routines/triggers: alleen tabellen en INSERT-gegevens zijn nodig.');
        }
        if ('TABLE' !== $type) { $this->totPuntkomma(); return; }
        if ($this->isVolgende('IF')) { $this->verwacht('NOT'); $this->verwacht('EXISTS'); }
        [$database, $naam] = $this->tabelNaam();
        if (!in_array($naam, self::TABELLEN, true)) { $this->totPuntkomma(); return; }
        $this->registreer($database, $naam);
        $tabel = &$this->tabellen[$database][$naam];
        if ($tabel['gedefinieerd'] || $tabel['rijen']->aantal() > 0) {
            throw $this->tokens->fout('Dezelfde brontabel is meermaals gedefinieerd; gebruik één volledige snapshot per afdeling.');
        }
        $this->verwacht('(');
        $groepen = []; $groep = []; $diepte = 0;
        while (true) {
            $token = $this->vereist();
            if ('symbol' === $token[0] && ')' === $token[1] && 0 === $diepte) { $groepen[] = $groep; break; }
            if ('symbol' === $token[0] && ',' === $token[1] && 0 === $diepte) { $groepen[] = $groep; $groep = []; continue; }
            if ('(' === $token[1] && 'symbol' === $token[0]) { ++$diepte; }
            if (')' === $token[1] && 'symbol' === $token[0]) { --$diepte; }
            $groep[] = $token;
        }
        foreach ($groepen as $groep) {
            if (count($groep) < 2 || !in_array($groep[0][0], ['ident', 'word'], true)) { continue; }
            if ('word' === $groep[0][0] && in_array(strtoupper($groep[0][1]), ['PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT', 'SPATIAL'], true)) { continue; }
            $kolom = $groep[0][1];
            if (isset($tabel['kolommen'][$kolom])) { throw $this->tokens->fout('Dubbele kolom in tabeldefinitie.'); }
            $woorden = array_map(static fn ($t) => $t[1], $groep);
            $charset = $this->charset($woorden);
            if (in_array(strtoupper($groep[1][1]), ['BINARY', 'VARBINARY', 'BLOB', 'TINYBLOB', 'MEDIUMBLOB', 'LONGBLOB'], true)) { $charset = 'binary'; }
            $default = null;
            foreach ($groep as $i => $deel) {
                if ('word' === $deel[0] && 'DEFAULT' === strtoupper($deel[1]) && isset($groep[$i + 1])) {
                    $v = $groep[$i + 1];
                    if (in_array($v[0], ['string', 'number'], true)) { $default = $v[1]; }
                }
            }
            $tabel['kolommen'][$kolom] = ['charset' => $charset, 'default' => $default];
        }
        if (!$tabel['kolommen']) { throw $this->tokens->fout('Geen kolommen gevonden in brontabel.'); }
        $opties = [];
        while (null !== ($token = $this->volgende()) && !('symbol' === $token[0] && ';' === $token[1])) { $opties[] = $token[1]; }
        if (null === $token) { throw $this->tokens->fout('Onvolledige CREATE TABLE-opdracht.'); }
        $tabel['charset'] = $this->charset($opties);
        $tabel['gedefinieerd'] = true;
    }

    private function insert(): void
    {
        // INSERT IGNORE en upserts kunnen rijen verbergen; daarvoor liever een schone export.
        $this->verwacht('INTO');
        [$database, $naam] = $this->tabelNaam();
        if (!in_array($naam, self::TABELLEN, true)) { $this->totPuntkomma(); return; }
        $this->registreer($database, $naam);
        $tabel = &$this->tabellen[$database][$naam];
        if ($this->isVolgende('(')) {
            $kolommen = [];
            do { $kolommen[] = $this->identifier(); } while ($this->isVolgende(','));
            $this->verwacht(')');
        } else { $kolommen = array_keys($tabel['kolommen']); }
        if (!$kolommen || count($kolommen) !== count(array_unique($kolommen))) {
            throw $this->tokens->fout('INSERT mist een bruikbare kolommenlijst. Exporteer ook CREATE TABLE of gebruik complete INSERTs.');
        }
        if ($tabel['gedefinieerd'] && array_diff($kolommen, array_keys($tabel['kolommen']))) {
            throw $this->tokens->fout('INSERT verwijst naar een kolom buiten de tabeldefinitie.');
        }
        $this->verwacht('VALUES');
        do {
            $this->verwacht('(');
            $rij = [];
            foreach ($kolommen as $i => $kolom) {
                if ($i > 0) { $this->verwacht(','); }
                [$waarde, $charset, $binair] = $this->waarde();
                $doelCharset = $tabel['kolommen'][$kolom]['charset'] ?? $tabel['charset'];
                $rij[$kolom] = $this->bytes($waarde, $charset ?? $this->verbindingCharset, $doelCharset, $binair);
            }
            $this->verwacht(')');
            foreach ($tabel['kolommen'] as $kolom => $metadata) {
                if (!array_key_exists($kolom, $rij)) { $rij[$kolom] = $metadata['default']; }
            }
            $tabel['rijen']->toevoegen($rij);
        } while ($this->isVolgende(','));
        $this->verwacht(';');
    }

    /** @return array{mixed, ?string, bool} */
    private function waarde(): array
    {
        $token = $this->vereist();
        $sign = '';
        if ('symbol' === $token[0] && in_array($token[1], ['-', '+'], true)) {
            $sign = $token[1]; $token = $this->vereist();
        }
        if ('string' === $token[0] && '' === $sign) { return [$token[1], null, false]; }
        if ('number' === $token[0]) {
            if ('' === $sign && preg_match('/^0x([a-f0-9]*)$/iD', $token[1], $m)) {
                return [$this->hex($m[1]), null, true];
            }
            $getal = $sign.$token[1];
            if (!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $getal)) { throw $this->tokens->fout('Ongeldige numerieke waarde.'); }
            return [$getal, null, false];
        }
        if ('word' === $token[0] && '' === $sign) {
            $woord = strtoupper($token[1]);
            if ('NULL' === $woord) { return [null, null, false]; }
            if ('TRUE' === $woord || 'FALSE' === $woord) { return ['TRUE' === $woord ? '1' : '0', null, false]; }
            if ('X' === $woord) { return [$this->hex($this->verwachtType('string')[1]), null, true]; }
            if ('B' === $woord) {
                $bits = $this->verwachtType('string')[1];
                if (!preg_match('/^[01]{1,63}$/D', $bits)) { throw $this->tokens->fout('Ongeldige bitwaarde.'); }
                return [(string) bindec($bits), null, false];
            }
            if ('N' === $woord || str_starts_with($woord, '_')) {
                $charset = 'N' === $woord ? 'utf8' : strtolower(substr($woord, 1));
                return [$this->verwachtType('string')[1], $charset, 'binary' === $charset];
            }
        }
        throw $this->tokens->fout('Alleen letterlijke INSERT-waarden worden ondersteund; exporteer zonder functies of SQL-expressies.');
    }

    private function bytes(mixed $waarde, ?string $van, ?string $naar, bool $binair): mixed
    {
        if (null === $waarde || $binair || null === $van || null === $naar || 'binary' === $van || 'binary' === $naar) { return $waarde; }
        $bron = $this->phpCharset($van); $doel = $this->phpCharset($naar);
        if ($bron === $doel) { return $waarde; }
        if (!mb_check_encoding((string) $waarde, $bron)) { throw $this->tokens->fout('Tekstbytes passen niet bij SET NAMES in de dump.'); }
        $bytes = mb_convert_encoding((string) $waarde, $doel, $bron);
        if (mb_convert_encoding($bytes, $bron, $doel) !== (string) $waarde) {
            throw $this->tokens->fout('Een tekstwaarde past niet in de tekenset van haar kolom; exporteer de dump met de oorspronkelijke tabeldefinities.');
        }
        // Herstel opgeslagen bytes, zodat LegacyImporter::tekst dezelfde correctie toepast als bij live MySQL.
        return $bytes;
    }

    private function phpCharset(string $charset): string
    {
        return match (strtolower($charset)) {
            'utf8', 'utf8mb3', 'utf8mb4' => 'UTF-8',
            'latin1', 'cp1252' => 'Windows-1252',
            'ascii' => 'ASCII',
            'latin2' => 'ISO-8859-2',
            default => throw $this->tokens->fout('Niet ondersteunde tekenset in SQL-dump: '.$charset.'.'),
        };
    }

    private function hex(string $hex): string
    {
        if (!preg_match('/^[a-f0-9]*$/iD', $hex)) { throw $this->tokens->fout('Ongeldige hexwaarde.'); }
        return hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
    }

    private function charset(array $woorden): ?string
    {
        foreach ($woorden as $i => $woord) {
            $naam = strtoupper($woord);
            if ('CHARSET' === $naam || ('CHARACTER' === $naam && 'SET' === strtoupper($woorden[$i + 1] ?? ''))) {
                $j = $i + ('CHARSET' === $naam ? 1 : 2);
                if ('=' === ($woorden[$j] ?? '')) { ++$j; }
                return strtolower($woorden[$j] ?? '') ?: null;
            }
        }
        return null;
    }

    private function instellingen(string $sql): void
    {
        if (preg_match('/\bNAMES\s+([a-z0-9_]+|\x27[a-z0-9_]+\x27)/i', $sql, $m)) {
            $this->verbindingCharset = strtolower(trim($m[1], "'"));
        }
        if (preg_match('/(?<![@\w])SQL_MODE\s*=\s*\x27([^\x27]*)\x27/i', $sql, $m)) {
            $modi = array_map('trim', explode(',', strtoupper($m[1])));
            $this->tokens->geenBackslashEscapes = in_array('NO_BACKSLASH_ESCAPES', $modi, true);
            $this->tokens->ansiQuotes = in_array('ANSI_QUOTES', $modi, true);
        }
    }

    private function registreer(string $database, string $naam): void
    {
        $this->tabellen[$database][$naam] ??= ['kolommen' => [], 'charset' => null, 'rijen' => new LegacyDumpRijen($naam), 'gedefinieerd' => false];
    }

    private function tabelNaam(): array
    {
        $eerste = $this->identifier();
        return $this->isVolgende('.') ? [$eerste, $this->identifier()] : [$this->database, $eerste];
    }

    private function identifier(): string
    {
        $token = $this->vereist();
        if (!in_array($token[0], ['ident', 'word'], true)) { throw $this->tokens->fout('Tabel- of kolomnaam verwacht.'); }
        return $token[1];
    }

    private function volgende(): ?array
    {
        if (null !== $this->wachtend) { $token = $this->wachtend; $this->wachtend = null; return $token; }
        while (null !== ($token = $this->tokens->volgende()) && 'comment' === $token[0]) {
            $this->instellingen($token[1]);
        }
        return $token;
    }

    private function vereist(): array
    {
        return $this->volgende() ?? throw $this->tokens->fout('De dump eindigt midden in een SQL-opdracht.');
    }

    private function verwachtType(string $type): array
    {
        $token = $this->vereist();
        if ($token[0] !== $type) { throw $this->tokens->fout('Onverwachte waarde in INSERT.'); }
        return $token;
    }

    private function verwacht(string $waarde): array
    {
        $token = $this->vereist();
        $type = ctype_alpha($waarde) ? 'word' : 'symbol';
        if ($type !== $token[0] || strtoupper($token[1]) !== $waarde) { throw $this->tokens->fout('Verwacht '.$waarde.'.'); }
        return $token;
    }

    private function isVolgende(string $waarde): bool
    {
        $token = $this->volgende();
        $type = ctype_alpha($waarde) ? 'word' : 'symbol';
        if (null !== $token && $type === $token[0] && strtoupper($token[1]) === $waarde) { return true; }
        $this->wachtend = $token;
        return false;
    }

    private function totPuntkomma(): void
    {
        while (null !== ($token = $this->volgende())) {
            if ('symbol' === $token[0] && ';' === $token[1]) { return; }
        }
        throw $this->tokens->fout('Onvolledige SQL-opdracht.');
    }
}

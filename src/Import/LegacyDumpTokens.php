<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Kleine streaming tokenizer voor MySQL-/MariaDB-dumps, zonder SQL-uitvoering. */
#[Exclude]
final class LegacyDumpTokens
{
    public bool $geenBackslashEscapes = false;
    public bool $ansiQuotes = false;
    private $bestand;
    private string $buffer = '';
    private int $positie = 0;
    private int $regel = 1;
    private bool $gzip;

    public function __construct(string $pad)
    {
        if (!is_file($pad) || !is_readable($pad)) {
            throw new \RuntimeException('SQL-dump bestaat niet of is niet leesbaar: '.$pad);
        }
        $this->gzip = str_ends_with(strtolower($pad), '.gz');
        if ($this->gzip && !function_exists('gzopen')) {
            throw new \RuntimeException('Voor .sql.gz is de PHP-extensie zlib nodig. Pak het bestand anders eerst uit.');
        }
        $this->bestand = $this->gzip ? gzopen($pad, 'rb') : fopen($pad, 'rb');
        if (false === $this->bestand) {
            throw new \RuntimeException('Kan de SQL-dump niet openen.');
        }
        if ("\xEF" === $this->kijk() && "\xBB" === $this->kijk(1) && "\xBF" === $this->kijk(2)) {
            $this->neem(); $this->neem(); $this->neem();
        }
    }

    /** @return array{string, string}|null */
    public function volgende(): ?array
    {
        while (null !== ($c = $this->kijk())) {
            if (ord($c) <= 32) { $this->neem(); continue; }
            if ('#' === $c || ('-' === $c && '-' === $this->kijk(1) && (null === $this->kijk(2) || ord($this->kijk(2)) <= 32))) {
                while (null !== ($c = $this->neem()) && "\n" !== $c) {}
                continue;
            }
            if ('/' === $c && '*' === $this->kijk(1)) {
                $this->neem(); $this->neem();
                $versie = '!' === $this->kijk() || ('M' === $this->kijk() && '!' === $this->kijk(1));
                $tekst = '';
                while (true) {
                    if (null === $this->kijk()) { throw $this->fout('Onvolledige SQL-commentaar.'); }
                    if ('*' === $this->kijk() && '/' === $this->kijk(1)) {
                        $this->neem(); $this->neem(); break;
                    }
                    $char = $this->neem();
                    if ($versie) {
                        $tekst .= $char;
                        if (strlen($tekst) > 1048576) { throw $this->fout('Versiecommentaar is te groot.'); }
                    }
                }
                if ($versie) { return ['comment', $tekst]; }
                continue;
            }
            if (in_array($c, ["'", '"', '`'], true)) {
                $quote = $this->neem();
                $ident = '`' === $quote || ('"' === $quote && $this->ansiQuotes);
                $waarde = '';
                while (null !== ($c = $this->neem())) {
                    if ($c === $quote) {
                        if ($this->kijk() === $quote) { $waarde .= $this->neem(); continue; }
                        return [$ident ? 'ident' : 'string', $waarde];
                    }
                    if ('\\' === $c && !$ident && !$this->geenBackslashEscapes) {
                        $c = $this->neem();
                        if (null === $c) { throw $this->fout('Onvolledige escape in tekstwaarde.'); }
                        $waarde .= match ($c) {
                            '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A",
                            '%', '_' => '\\'.$c,
                            default => $c,
                        };
                    } else { $waarde .= $c; }
                    if (strlen($waarde) > 33554432) { throw $this->fout('Eén tekstwaarde is groter dan 32 MiB.'); }
                }
                throw $this->fout('Onvolledige tekstwaarde of identifier.');
            }
            if (ctype_digit($c) || ('.' === $c && null !== $this->kijk(1) && ctype_digit($this->kijk(1)))) {
                $waarde = '';
                while (null !== ($c = $this->kijk()) && str_contains('0123456789abcdefABCDEFxX.+-', $c)) { $waarde .= $this->neem(); }
                return ['number', $waarde];
            }
            if (ctype_alpha($c) || '_' === $c || '$' === $c || ord($c) >= 128) {
                $waarde = '';
                while (null !== ($c = $this->kijk()) && (ctype_alnum($c) || '_' === $c || '$' === $c || ord($c) >= 128)) { $waarde .= $this->neem(); }
                return ['word', $waarde];
            }
            return ['symbol', $this->neem()];
        }
        return null;
    }

    public function fout(string $melding): \RuntimeException
    {
        // Geen rijwaarden in de foutmelding: een dump kan codes en persoonsgegevens bevatten.
        return new \RuntimeException(sprintf('SQL-dump, regel %d: %s', $this->regel, $melding));
    }

    public function sluiten(): void
    {
        if (is_resource($this->bestand)) {
            $this->gzip ? gzclose($this->bestand) : fclose($this->bestand);
        }
    }

    public function __destruct() { $this->sluiten(); }

    private function kijk(int $vooruit = 0): ?string
    {
        while ($this->positie + $vooruit >= strlen($this->buffer)) {
            $this->buffer = substr($this->buffer, $this->positie);
            $this->positie = 0;
            $deel = $this->gzip ? gzread($this->bestand, 65536) : fread($this->bestand, 65536);
            if (false === $deel) { throw $this->fout('Kan dump niet verder lezen.'); }
            if ('' === $deel) { return null; }
            $this->buffer .= $deel;
        }
        return $this->buffer[$this->positie + $vooruit];
    }

    private function neem(): ?string
    {
        $c = $this->kijk();
        if (null !== $c) { ++$this->positie; if ("\n" === $c) { ++$this->regel; } }
        return $c;
    }
}

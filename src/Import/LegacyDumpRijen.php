<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Tijdelijk privaat rijbestand: ook grote tabellen hoeven niet geheel in het geheugen. */
#[Exclude]
final class LegacyDumpRijen
{
    private $bestand;
    private int $aantal = 0;
    private ?int $laatsteId = null;
    private bool $sorteren = false;
    private ?array $index = null;

    public function __construct(public readonly string $naam)
    {
        $this->bestand = tmpfile();
        if (false === $this->bestand) {
            throw new \RuntimeException('Kan geen tijdelijk rijbestand maken; controleer de vrije schijfruimte en de PHP-tempmap.');
        }
    }

    public function toevoegen(array $rij): void
    {
        if (isset($rij['id'])) {
            if (!preg_match('/^\d+$/D', (string) $rij['id']) || strlen((string) $rij['id']) > 18) {
                throw new \RuntimeException('Ongeldige numerieke id in '.$this->naam.'.');
            }
            $id = (int) $rij['id'];
            $this->sorteren = $this->sorteren || (null !== $this->laatsteId && $id <= $this->laatsteId);
            $this->laatsteId = $id;
        }
        $data = serialize($rij);
        $this->schrijf(pack('N', strlen($data)).$data);
        ++$this->aantal;
    }

    public function voorbereiden(): void
    {
        if (!$this->sorteren || null !== $this->index) {
            return;
        }
        $this->index = [];
        rewind($this->bestand);
        while (!feof($this->bestand)) {
            $positie = ftell($this->bestand);
            $rij = $this->volgende();
            if (null === $rij) {
                break;
            }
            if (!isset($rij['id'])) {
                throw new \RuntimeException('Niet elke rij in '.$this->naam.' heeft een id.');
            }
            $id = (int) $rij['id'];
            if (isset($this->index[$id])) {
                throw new \RuntimeException(sprintf('Dubbele id %d in brontabel %s; import gestopt.', $id, $this->naam));
            }
            $this->index[$id] = $positie;
        }
        ksort($this->index, SORT_NUMERIC);
    }

    public function rijen(): iterable
    {
        $this->voorbereiden();
        if (null !== $this->index) {
            foreach ($this->index as $positie) {
                if (0 !== fseek($this->bestand, $positie)) {
                    throw new \RuntimeException('Kan het tijdelijke rijbestand niet lezen.');
                }
                yield $this->volgende();
            }
            return;
        }
        rewind($this->bestand);
        while (null !== ($rij = $this->volgende())) {
            yield $rij;
        }
    }

    public function aantal(): int { return $this->aantal; }

    public function sluiten(): void
    {
        if (is_resource($this->bestand)) {
            fclose($this->bestand);
        }
        $this->index = null;
    }

    public function __destruct() { $this->sluiten(); }

    private function schrijf(string $data): void
    {
        $positie = 0;
        while ($positie < strlen($data)) {
            $geschreven = fwrite($this->bestand, substr($data, $positie));
            if (false === $geschreven || 0 === $geschreven) {
                throw new \RuntimeException('Tijdelijke opslag is vol of niet schrijfbaar.');
            }
            $positie += $geschreven;
        }
    }

    private function lees(int $lengte): string
    {
        $data = '';
        while (strlen($data) < $lengte) {
            $deel = fread($this->bestand, $lengte - strlen($data));
            if (false === $deel || '' === $deel) {
                throw new \RuntimeException('Onvolledig tijdelijk rijbestand.');
            }
            $data .= $deel;
        }
        return $data;
    }

    private function volgende(): ?array
    {
        $kop = fread($this->bestand, 4);
        if ('' === $kop && feof($this->bestand)) {
            return null;
        }
        if (false === $kop || 4 !== strlen($kop)) {
            throw new \RuntimeException('Beschadigd tijdelijk rijbestand.');
        }
        $lengte = unpack('N', $kop)[1];
        $rij = unserialize($this->lees($lengte), ['allowed_classes' => false]);
        if (!is_array($rij)) {
            throw new \RuntimeException('Beschadigde tijdelijke rij.');
        }
        return $rij;
    }
}

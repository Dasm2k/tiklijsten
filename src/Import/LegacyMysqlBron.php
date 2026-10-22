<?php

namespace App\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Ondersteuning voor de bestaande import vanaf een bereikbare oude MySQL-database. */
#[Exclude]
final class LegacyMysqlBron implements LegacyBron
{
    private Connection $verbinding;
    private array $kolommen = [];

    public function __construct(string $dsn)
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($dsn);
        // Lees de oorspronkelijke bytes van de latin1-kolommen van de oude toepassing.
        $this->verbinding = DriverManager::getConnection($params + ['charset' => 'latin1']);
        foreach ($this->verbinding->fetchAllAssociative(
            'SELECT table_name AS tabel, column_name AS kolom FROM information_schema.columns WHERE table_schema = DATABASE()',
        ) as $rij) {
            $this->kolommen[$rij['tabel']][] = $rij['kolom'];
        }
    }

    public function heeftTabel(string $tabel): bool
    {
        return isset($this->kolommen[$tabel]);
    }

    public function rijen(string $tabel): iterable
    {
        if (!$this->heeftTabel($tabel)) {
            throw new \RuntimeException('Brontabel ontbreekt: '.$tabel);
        }
        $sql = 'SELECT * FROM '.$this->verbinding->quoteIdentifier($tabel);
        if (in_array('id', $this->kolommen[$tabel], true)) {
            $sql .= ' ORDER BY '.$this->verbinding->quoteIdentifier('id');
        }
        yield from $this->verbinding->iterateAssociative($sql);
    }

    public function sluiten(): void
    {
        $this->verbinding->close();
    }
}

<?php

namespace App\Import;

/** Alleen de gegevens die de migratie leest; een dump wordt nooit als SQL uitgevoerd. */
interface LegacyBron
{
    public function heeftTabel(string $tabel): bool;

    /** @return iterable<array<string, mixed>> Rijen in oplopende id-volgorde, indien aanwezig. */
    public function rijen(string $tabel): iterable;

    public function sluiten(): void;
}

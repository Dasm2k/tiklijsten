<?php

namespace App\Enum;

/**
 * Vervangt de numerieke "levels" uit de oude toepassing.
 * Rollen zijn hiërarchisch (zie security.yaml): elke rol bevat de rechten van de rollen erboven in deze lijst.
 */
enum Rol: string
{
    case LID = 'lid';             // level 1-49
    case FOUTEN = 'fouten';       // level >= 50: foutmeldingen opvolgen
    case LIJSTEN = 'lijsten';     // level >= 2200: aanwezigheidslijsten bekijken/bewerken
    case PERSONEEL = 'personeel'; // level >= 2250: personeel beheren, prestaties van iedereen
    case ARCHIEF = 'archief';     // level >= 2300: lijsten openen/sluiten/archiveren
    case BEHEER = 'beheer';       // level >= 5000: instellingen, mailinglijsten, voertuigen, stamgegevens
    case ADMIN = 'admin';         // level 10000
    case POST = 'post';           // level 1000/2000: tik-pc van een post (kiosk)

    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::LID => 'Lid',
            self::FOUTEN => 'Foutmeldingen opvolgen',
            self::LIJSTEN => 'Lijsten beheren',
            self::PERSONEEL => 'Personeel beheren',
            self::ARCHIEF => 'Lijsten archiveren',
            self::BEHEER => 'Beheerder',
            self::ADMIN => 'Administrator',
            self::POST => 'Tik-pc (post)',
        };
    }

    /** Rangorde om te bepalen welke rollen iemand mag toekennen. */
    public function gewicht(): int
    {
        return match ($this) {
            self::LID => 1,
            self::FOUTEN => 2,
            self::LIJSTEN => 3,
            self::PERSONEEL => 4,
            self::ARCHIEF => 5,
            self::BEHEER => 6,
            self::ADMIN => 7,
            self::POST => 6,
        };
    }

    public static function vanLegacyLevel(int $level): self
    {
        return match (true) {
            $level === 1000 || $level === 2000 => self::POST,
            $level >= 10000 => self::ADMIN,
            $level >= 5000 => self::BEHEER,
            $level >= 2300 => self::ARCHIEF,
            $level >= 2250 => self::PERSONEEL,
            $level >= 2200 => self::LIJSTEN,
            $level >= 50 => self::FOUTEN,
            default => self::LID,
        };
    }
}

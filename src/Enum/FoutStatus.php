<?php

namespace App\Enum;

enum FoutStatus: int
{
    case NIEUW = 0;
    case TOEGEWEZEN = 1;
    case EXTRA_INFO = 2;
    case BUITENDIENST = 3;
    case DEFINITIEF_BUITENDIENST = 4;
    case AFGEWERKT = 5;

    public function label(): string
    {
        return match ($this) {
            self::NIEUW => 'Nieuw',
            self::TOEGEWEZEN => 'Toegewezen',
            self::EXTRA_INFO => 'Extra info',
            self::BUITENDIENST => 'Buitendienst',
            self::DEFINITIEF_BUITENDIENST => 'Definitief buitendienst',
            self::AFGEWERKT => 'Afgewerkt',
        };
    }

    /** Bootstrap-kleur voor de statusbadge. */
    public function kleur(): string
    {
        return match ($this) {
            self::NIEUW => 'secondary',
            self::TOEGEWEZEN => 'primary',
            self::EXTRA_INFO => 'info',
            self::BUITENDIENST => 'warning',
            self::DEFINITIEF_BUITENDIENST => 'danger',
            self::AFGEWERKT => 'success',
        };
    }

    public function isAfgesloten(): bool
    {
        return $this === self::DEFINITIEF_BUITENDIENST || $this === self::AFGEWERKT;
    }
}

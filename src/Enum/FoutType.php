<?php

namespace App\Enum;

enum FoutType: int
{
    case VOERTUIG = 1;
    case TOESTEL = 2;
    case KLEDIJ = 3;
    case KADAVER = 4;
    case ANDERS = 50;

    public function label(): string
    {
        return match ($this) {
            self::VOERTUIG => 'Voertuig',
            self::TOESTEL => 'Toestel',
            self::KLEDIJ => 'Kledij',
            self::KADAVER => 'Kadaver',
            self::ANDERS => 'Iets anders',
        };
    }
}

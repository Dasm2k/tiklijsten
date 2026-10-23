<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class AppExtension extends AbstractExtension
{
    public function __construct(private readonly \App\Service\AfdelingContext $context) {}
    public function getFunctions(): array
    {
        return [new \Twig\TwigFunction('huidige_afdeling', [$this->context, 'huidige'])];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('duur', [self::class, 'duur']),
            new TwigFilter('peloton', [self::class, 'peloton']),
        ];
    }

    /** Seconden als "45m" of "02u05" (zelfde notatie als de oude toepassing). */
    public static function duur(?int $seconden): string
    {
        $seconden = (int) $seconden;
        if ($seconden < 60) {
            return '< 1m';
        }
        $minuten = intdiv($seconden, 60);
        if ($minuten < 60) {
            return $minuten.'m';
        }
        $uren = intdiv($minuten, 60);
        $rest = $minuten % 60;
        return sprintf('%02du', $uren).($rest ? sprintf('%02d', $rest) : '');
    }

    public static function peloton(?int $peloton): string
    {
        return $peloton ? $peloton.'e peloton' : 'Niet ingedeeld';
    }
}

<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Alle tijden in de database zijn lokale Belgische tijd (zoals in de oude toepassing).
        date_default_timezone_set('Europe/Brussels');
        parent::boot();
    }
}

<?php

namespace App\Tests\Unit;

use App\Entity\{Aanwezigheidslijst, Afdeling, Persoon, Rang};
use App\Enum\Rol;
use App\Service\SpecialeLijstRechten;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class SpecialeLijstRechtenTest extends TestCase
{
    private function rechten(): SpecialeLijstRechten
    {
        return new SpecialeLijstRechten(new RoleHierarchy(['ROLE_BEHEER' => ['ROLE_ARCHIEF'], 'ROLE_ADMIN' => ['ROLE_BEHEER'], 'ROLE_SUPERADMIN' => ['ROLE_ADMIN']]));
    }

    public function testOfficiersrangEnAfkortingGevenRechtenOnafhankelijkVanPersoonlijkeRol(): void
    {
        $afdeling = new Afdeling();
        $lijst = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setSpeciaal(true);
        $rechten = $this->rechten();
        foreach ([['Luitenant', 'Lt.'], ['Kapitein', 'Kap.'], ['Majoor', 'Maj.'], ['', 'Lt.'], ['', 'Kap.'], ['', 'Kpt.'], ['', 'Kapt.'], ['', 'Maj.'], ['KAPITEIN', '']] as [$naam, $kort]) {
            foreach ([Rol::LID, Rol::FOUTEN, Rol::LIJSTEN, Rol::PERSONEEL, Rol::ARCHIEF] as $rol) {
                $persoon = (new Persoon())->setAfdeling($afdeling)->setRol($rol)->setRang((new Rang())->setNaam($naam)->setKort($kort));
                self::assertFalse($rechten->isBeheerder($persoon));
                self::assertTrue($rechten->magAfsluiten($lijst, $persoon), $naam.' '.$kort.' / '.$rol->value);
                self::assertSame($rol, $persoon->getRol());
            }
        }
    }

    public function testRangOmzeiltGeenAccountOfAfdelingscontrole(): void
    {
        $afdeling = new Afdeling();
        (new \ReflectionProperty(Afdeling::class, 'id'))->setValue($afdeling, 1);
        $ander = new Afdeling();
        (new \ReflectionProperty(Afdeling::class, 'id'))->setValue($ander, 2);
        $lijst = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setSpeciaal(true);
        $rechten = $this->rechten();
        $officier = (new Persoon())->setAfdeling($afdeling)->setRol(Rol::LID)->setRang((new Rang())->setNaam('Luitenant')->setKort('Lt.'));
        self::assertTrue($rechten->magAfsluiten($lijst, $officier));
        $inactief = clone $officier;$inactief->setActief(false);
        $verwijderd = clone $officier;$verwijderd->markeerVerwijderd();
        $systeemaccount = clone $officier;$systeemaccount->setSysteemaccount(true);
        $andereAfdeling = clone $officier;$andereAfdeling->setAfdeling($ander);
        $korporaal = clone $officier;$korporaal->setRang((new Rang())->setNaam('Korporaal')->setKort('Kpl.'));
        $zonderRang = clone $officier;$zonderRang->setRang(null);
        foreach ([$inactief, $verwijderd, $systeemaccount, $andereAfdeling, $korporaal, $zonderRang, null] as $persoon) {
            self::assertFalse($rechten->magAfsluiten($lijst, $persoon));
        }
    }
}

<?php

namespace App\Tests\Unit;

use App\Enum\Rol;
use App\Import\LegacyImporter;
use App\Security\LegacyMd5PasswordHasher;
use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyTest extends TestCase
{
    public static function levels(): iterable
    {
        yield [1, Rol::LID];
        yield [49, Rol::LID];
        yield [50, Rol::FOUTEN];
        yield [1000, Rol::POST];
        yield [2000, Rol::POST];
        yield [2200, Rol::LIJSTEN];
        yield [2250, Rol::PERSONEEL];
        yield [2300, Rol::ARCHIEF];
        yield [5000, Rol::BEHEER];
        yield [10000, Rol::ADMIN];
    }

    #[DataProvider('levels')]
    public function testLevelsWordenRollen(int $level, Rol $verwacht): void
    {
        self::assertSame($verwacht, Rol::vanLegacyLevel($level));
    }

    public function testMd5CodesWordenHerkend(): void
    {
        $hasher = new LegacyMd5PasswordHasher();
        self::assertTrue($hasher->verify(md5('1234'), '1234'));
        self::assertTrue($hasher->verify(strtoupper(md5('1234')), '1234'));
        self::assertFalse($hasher->verify(md5('1234'), '4321'));
        self::assertFalse($hasher->verify(password_hash('1234', PASSWORD_BCRYPT), '1234'));
        self::assertTrue($hasher->needsRehash(md5('1234')));
    }

    public function testTekstOpkuisen(): void
    {
        self::assertSame('Brand & co "test"', LegacyImporter::tekst('Brand &amp; co &quot;test&quot;'));
        self::assertSame('René', LegacyImporter::tekst("Ren\xE9"));
        self::assertSame("Probleem\n\n- Helm\n- Laarzen", LegacyImporter::tekst('Probleem<br/><ul><li>Helm</li><li>Laarzen</li></ul>'));
    }

    public function testNulDatums(): void
    {
        self::assertNull(LegacyImporter::datum('0000-00-00'));
        self::assertNull(LegacyImporter::datumTijd('0000-00-00 00:00:00'));
        self::assertSame('2024-02-29', LegacyImporter::datum('2024-02-29'));
        self::assertSame('2024-02-29 13:05:00', LegacyImporter::datumTijd('2024-02-29 13:05:00'));
    }

    public function testDuurNotatieZoalsVroeger(): void
    {
        self::assertSame('< 1m', AppExtension::duur(30));
        self::assertSame('45m', AppExtension::duur(45 * 60));
        self::assertSame('02u', AppExtension::duur(2 * 3600));
        self::assertSame('02u05', AppExtension::duur(2 * 3600 + 5 * 60));
        self::assertSame('1e peloton', AppExtension::peloton(1));
        self::assertSame('Niet ingedeeld', AppExtension::peloton(null));
    }
}

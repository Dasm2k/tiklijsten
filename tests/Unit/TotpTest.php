<?php
namespace App\Tests\Unit;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testRfc6238Vectors(): void
    {
        $totp=new Totp();$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        // Last six digits of the RFC's eight-digit SHA-1 vectors.
        foreach ([59=>'287082',1111111109=>'081804',1111111111=>'050471',1234567890=>'005924',2000000000=>'279037',20000000000=>'353130'] as $time=>$code) {
            self::assertSame($code,$totp->code($secret,intdiv($time,30)));
            self::assertSame(intdiv($time,30),$totp->matchingStep($secret,$code,$time));
        }
        self::assertNull($totp->matchingStep($secret,'12345',59));
        self::assertNull($totp->matchingStep($secret,'287082',300));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/',$totp->generateSecret());
    }
}

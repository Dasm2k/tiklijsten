<?php
namespace App\Security;

/** RFC 6238: SHA-1, six digits, thirty-second steps. No external QR service. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $secret = '';
        for ($i = 0; $i < 32; ++$i) { $secret .= self::ALPHABET[random_int(0, 31)]; }
        return $secret;
    }

    public function code(string $secret, int $step): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if (false === $value) { throw new \InvalidArgumentException('Ongeldige TOTP-sleutel.'); }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) { $key .= chr(bindec(substr($bits, $i, 8))); }
        $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function matchingStep(string $secret, string $code, ?int $now = null): ?int
    {
        if (!preg_match('/\A[0-9]{6}\z/', $code)) { return null; }
        $step = intdiv($now ?? time(), 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if ($candidate >= 0 && hash_equals($this->code($secret, $candidate), $code)) { return $candidate; }
        }
        return null;
    }
}

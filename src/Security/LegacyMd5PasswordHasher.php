<?php

namespace App\Security;

use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Enkel om codes uit de oude PHP 5-toepassing (ongezouten md5) te controleren.
 * Na een geslaagde controle zet Symfony de code automatisch om naar bcrypt.
 */
final class LegacyMd5PasswordHasher implements PasswordHasherInterface
{
    use CheckPasswordLengthTrait;

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        throw new \LogicException('Nieuwe codes worden nooit meer als md5 opgeslagen.');
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if (32 !== \strlen($hashedPassword) || !ctype_xdigit($hashedPassword) || $this->isPasswordTooLong($plainPassword)) {
            return false;
        }
        return hash_equals(strtolower($hashedPassword), md5($plainPassword));
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

final class OpaqueReference
{
    // 'fam' / 'tax': Leo opaque ids for own family members and tax profiles (RC2 Batch 2 · A1, L08/L13b).
    private const TYPES = ['crt', 'bnf', 'app', 'cnf', 'ver', 'pur', 'rsv', 'pmt', 'fam', 'tax'];

    public static function issue(string $type): string
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid reference type');
        }

        return $type.'_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function valid(string $ref, string $type): bool
    {
        if (! in_array($type, self::TYPES, true) || ! preg_match('/\A'.preg_quote($type, '/').'_[A-Za-z0-9_-]{43}\z/D', $ref)) {
            return false;
        }
        $value = substr($ref, 4);
        $bytes = base64_decode(strtr($value, '-_', '+/').'=', true);

        return $bytes !== false && strlen($bytes) === 32 && rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $value;
    }

    public static function key(string $ref, string $type): string
    {
        if (! self::valid($ref, $type)) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }

        return hash('sha256', $ref);
    }
}

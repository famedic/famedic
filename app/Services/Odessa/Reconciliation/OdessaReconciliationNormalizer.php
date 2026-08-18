<?php

namespace App\Services\Odessa\Reconciliation;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OdessaReconciliationNormalizer
{
    public static function header(mixed $value): string
    {
        $value = self::removeAccents(mb_strtolower(trim((string) $value)));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }

        $value = preg_replace('/\s+/', ' ', trim((string) $value)) ?? trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function identifier(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+\.0$/', $value)) {
            $value = substr($value, 0, -2);
        }

        return $value;
    }

    public static function email(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $parts = preg_split('/[;,|\s]+/', $value) ?: [];
        foreach ($parts as $part) {
            $part = mb_strtolower(trim($part));
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                return $part;
            }
        }

        return mb_strtolower($value);
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
                if ($date) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                // Try next format.
            }
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function comparableName(?string ...$parts): string
    {
        $name = implode(' ', array_filter(array_map(fn ($v) => self::text($v), $parts)));
        $name = self::removeAccents(mb_strtolower($name));

        return preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
    }

    public static function identityKey(?string $firstName, ?string $paternal, ?string $maternal, ?string $birthDate): string
    {
        return implode('|', [
            self::comparableName($firstName),
            self::comparableName($paternal),
            self::comparableName($maternal),
            trim((string) $birthDate),
        ]);
    }

    public static function removeAccents(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }
}

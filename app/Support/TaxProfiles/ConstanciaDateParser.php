<?php

namespace App\Support\TaxProfiles;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

final class ConstanciaDateParser
{
    /**
     * @var array<string, int>
     */
    private const SPANISH_MONTHS = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'setiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    /**
     * Normaliza fechas de constancia SAT (español o numéricas) a Y-m-d para BD.
     */
    public static function parseForStorage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        if (preg_match('/^(\d{1,2})\s+de\s+([a-záéíóú]+)\s+de\s+(\d{4})$/iu', $value, $matches)) {
            $monthKey = self::normalizeMonthToken($matches[2]);
            $month = self::SPANISH_MONTHS[$monthKey] ?? null;

            if ($month === null) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', (int) $matches[3], $month, (int) $matches[1]);
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (InvalidFormatException) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeMonthToken(string $month): string
    {
        $month = mb_strtolower(trim($month), 'UTF-8');

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú'],
            ['a', 'e', 'i', 'o', 'u'],
            $month
        );
    }
}

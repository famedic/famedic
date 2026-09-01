<?php

namespace App\Support\Exports;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Serial Excel para fechas operativas en America/Monterrey.
 */
class MonterreyExcelSerial
{
    /**
     * Instante UTC (p. ej. carts.created_at) → reloj Monterrey → serial Excel.
     */
    public static function from(mixed $date): ?float
    {
        if ($date === null || $date === '') {
            return null;
        }

        $carbon = $date instanceof CarbonInterface
            ? $date->copy()
            : Carbon::parse($date);

        return Date::dateTimeToExcel(localizedDate($carbon));
    }

    /**
     * Reloj operativo Monterrey persistido sin conversión UTC (citas).
     *
     * Conserva Y-m-d H:i:s tal como se muestra en negocio, independiente de APP_TIMEZONE.
     */
    public static function fromOperationalWallClock(mixed $date): ?float
    {
        if ($date === null || $date === '') {
            return null;
        }

        $carbon = $date instanceof CarbonInterface
            ? $date->copy()
            : Carbon::parse($date);

        $monterreyWall = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $carbon->format('Y-m-d H:i:s'),
            'America/Monterrey',
        );

        return Date::dateTimeToExcel($monterreyWall);
    }
}

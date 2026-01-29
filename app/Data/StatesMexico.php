<?php

namespace App\Data;

class StatesMexico
{
    public const ESTADOS = [
        'AS' => 'Aguascalientes',
        'BC' => 'Baja California',
        'BS' => 'Baja California Sur',
        'CC' => 'Campeche',
        'CL' => 'Coahuila',
        'CM' => 'Colima',
        'CS' => 'Chiapas',
        'CH' => 'Chihuahua',
        'DF' => 'Ciudad de México',
        'DG' => 'Durango',
        'GT' => 'Guanajuato',
        'GR' => 'Guerrero',
        'HG' => 'Hidalgo',
        'JC' => 'Jalisco',
        'EM' => 'Estado de México',
        'MI' => 'Michoacán',
        'MO' => 'Morelos',
        'NA' => 'Nayarit',
        'NL' => 'Nuevo León',
        'OA' => 'Oaxaca',
        'PU' => 'Puebla',
        'QT' => 'Querétaro',
        'QR' => 'Quintana Roo',
        'SL' => 'San Luis Potosí',
        'SI' => 'Sinaloa',
        'SO' => 'Sonora',
        'TB' => 'Tabasco',
        'TM' => 'Tamaulipas',
        'TL' => 'Tlaxcala',
        'VE' => 'Veracruz',
        'YU' => 'Yucatán',
        'ZA' => 'Zacatecas'
    ];

    public static function todos(): array
    {
        return self::ESTADOS;
    }

    public static function obtenerNombre(string $clave): ?string
    {
        return self::ESTADOS[$clave] ?? null;
    }

    public static function claves(): array
    {
        return array_keys(self::ESTADOS);
    }

    public static function existe(string $clave): bool
    {
        return array_key_exists($clave, self::ESTADOS);
    }
}
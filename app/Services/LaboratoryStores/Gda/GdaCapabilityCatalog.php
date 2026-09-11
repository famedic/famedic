<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaCapabilityCatalog
{
    public const MAP = [
        'LABORATORIO' => ['slug' => 'laboratorio', 'name' => 'Laboratorio'],
        'RAYOS X' => ['slug' => 'rayos_x', 'name' => 'Rayos X'],
        'RAYOS X ESPECIALES' => ['slug' => 'rayos_x_especiales', 'name' => 'Rayos X Especiales'],
        'ULTRASONIDO CONVENCIONAL' => ['slug' => 'ultrasonido_convencional', 'name' => 'Ultrasonido Convencional'],
        'ULTRASONIDO ESPECIAL' => ['slug' => 'ultrasonido_especial', 'name' => 'Ultrasonido Especial'],
        'BIOPSIAS' => ['slug' => 'biopsias', 'name' => 'Biopsias'],
        'MANOMETRIA' => ['slug' => 'manometria', 'name' => 'Manometria'],
        'ENDOSCOPIA' => ['slug' => 'endoscopia', 'name' => 'Endoscopia'],
        'COLONOSCOPIA' => ['slug' => 'colonoscopia', 'name' => 'Colonoscopia'],
        'TOMOGRAFIA' => ['slug' => 'tomografia', 'name' => 'Tomografia'],
        'RESONANCIA MAGNETICA' => ['slug' => 'resonancia_magnetica', 'name' => 'Resonancia Magnetica'],
        'AUDIOMETRIA' => ['slug' => 'audiometria', 'name' => 'Audiometria'],
        'COLPOSCOPIA' => ['slug' => 'colposcopia', 'name' => 'Colposcopia'],
        'GAMMAGRAMA/MEDICINA NUCLEAR' => ['slug' => 'gammagrama_medicina_nuclear', 'name' => 'Gammagrama / Medicina Nuclear'],
        'DENSITOMETRIA' => ['slug' => 'densitometria', 'name' => 'Densitometria'],
        'ECOCARDIOGRAMA' => ['slug' => 'ecocardiograma', 'name' => 'Ecocardiograma'],
        'ECOCARDIOGRAFIA CON DOBUTAMINA' => ['slug' => 'ecocardiografia_con_dobutamina', 'name' => 'Ecocardiografia con Dobutamina'],
        'PRUEBA DE ESFUERZO' => ['slug' => 'prueba_de_esfuerzo', 'name' => 'Prueba de Esfuerzo'],
        'ELECTROCARDIO' => ['slug' => 'electrocardio', 'name' => 'Electrocardio'],
        'ESPIROMETRIA' => ['slug' => 'espirometria', 'name' => 'Espirometria'],
        'MONITOREO HOLTER' => ['slug' => 'monitoreo_holter', 'name' => 'Monitoreo Holter'],
        'MASTOGRAFIA' => ['slug' => 'mastografia', 'name' => 'Mastografia'],
        'MONITOREO ARTERIAL' => ['slug' => 'monitoreo_arterial', 'name' => 'Monitoreo Arterial'],
        'POLISOMNOGRAFIA' => ['slug' => 'polisomnografia', 'name' => 'Polisomnografia'],
        'ELECTROENCEFALO' => ['slug' => 'electroencefalo', 'name' => 'Electroencefalo'],
        'EXAMEN MEDICO' => ['slug' => 'examen_medico', 'name' => 'Examen Medico'],
        'PAPANICOLAOU' => ['slug' => 'papanicolaou', 'name' => 'Papanicolaou'],
        'SOMATOMETRIA' => ['slug' => 'somatometria', 'name' => 'Somatometria'],
        'ORTOPANTOMOGRAFIA' => ['slug' => 'ortopantomografia', 'name' => 'Ortopantomografia'],
    ];

    public function byColumn(string $column): ?array
    {
        $key = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $column)));

        return self::MAP[$key] ?? null;
    }
}

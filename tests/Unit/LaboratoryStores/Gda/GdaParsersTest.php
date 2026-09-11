<?php

use App\Services\LaboratoryStores\Gda\GdaCapabilityCatalog;
use App\Services\LaboratoryStores\Gda\GdaCapabilityParser;
use App\Services\LaboratoryStores\Gda\GdaScheduleParser;

it('parses GDA schedule text into ISO weekdays', function () {
    $parser = new GdaScheduleParser;

    $result = $parser->parse('LUNES A VIERNES: 07:00 A 15:00 // SÁBADOS: 08:00 A 12:00 // DOMINGOS: N/A');

    expect($result['days'][1]['opens_at'])->toBe('07:00:00')
        ->and($result['days'][5]['closes_at'])->toBe('15:00:00')
        ->and($result['days'][6]['opens_at'])->toBe('08:00:00')
        ->and($result['days'][7]['is_closed'])->toBeTrue()
        ->and($result['warnings'])->toBe([]);
});

it('parses real lower-case and malformed GDA schedule variants', function () {
    $parser = new GdaScheduleParser;

    $lowerCase = $parser->parse('lunes a sabado de 07:00 a 13:00');
    $malformedSeparator = $parser->parse('LUNES A VIERNES: 06:00 A< 19:00');
    $closed = $parser->parse('NA');

    expect($lowerCase['days'][1]['opens_at'])->toBe('07:00:00')
        ->and($lowerCase['days'][6]['closes_at'])->toBe('13:00:00')
        ->and($malformedSeparator['days'][1]['closes_at'])->toBe('19:00:00')
        ->and($closed['days'][1]['is_closed'])->toBeTrue()
        ->and($closed['days'][7]['is_closed'])->toBeTrue();
});

it('parses capabilities using only the supported marker', function () {
    $parser = new GdaCapabilityParser(new GdaCapabilityCatalog);

    $result = $parser->parse([
        'LABORATORIO' => ' a ',
        'RAYOS X' => '',
        'TOMOGRAFIA' => 'A',
        'MASTOGRAFIA' => 'x',
    ]);

    expect($result['enabled'])->toBe(['laboratorio', 'tomografia'])
        ->and($result['warnings'])->toHaveCount(1);
});

it('matches multiline capability headers from the GDA workbook', function () {
    $parser = new GdaCapabilityParser(new GdaCapabilityCatalog);

    $result = $parser->parse([
        'RAYOS X '."\n".'ESPECIALES' => 'a',
        'ULTRASONIDO'."\n".'CONVENCIONAL' => 'a',
        'ULTRASONIDO'."\n".'ESPECIAL' => 'a',
        'RESONANCIA'."\n".'MAGNETICA' => 'a',
        'PRUEBA '."\n".'DE ESFUERZO' => 'a',
        'MONITOREO'."\n".'HOLTER' => 'a',
        'MONITOREO'."\n".'ARTERIAL' => 'a',
    ]);

    expect($result['enabled'])->toBe([
        'rayos_x_especiales',
        'ultrasonido_convencional',
        'ultrasonido_especial',
        'resonancia_magnetica',
        'prueba_de_esfuerzo',
        'monitoreo_holter',
        'monitoreo_arterial',
    ])->and($result['warnings'])->toBe([]);
});

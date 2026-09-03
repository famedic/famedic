<?php

use App\Services\LaboratoryStores\Gda\GdaCoordinateNormalizer;
use App\Services\LaboratoryStores\Gda\GdaPhoneNormalizer;
use App\Services\LaboratoryStores\Gda\GdaPostalCodeNormalizer;
use App\Services\LaboratoryStores\Gda\GdaStringNormalizer;

it('normalizes names without removing meaningful words', function () {
    $normalizer = new GdaStringNormalizer;

    expect($normalizer->normalize('  ÁNZURES - TULYEHUALCO.  '))
        ->toBe('anzures tulyehualco')
        ->and($normalizer->normalize('ANZURES'))
        ->toBe('anzures');
});

it('pads postal codes and rejects impossible values', function () {
    $normalizer = new GdaPostalCodeNormalizer;

    expect($normalizer->normalize('1010')->value)->toBe('01010')
        ->and($normalizer->normalize(7)->value)->toBe('00007')
        ->and($normalizer->normalize('123456')->isValid())->toBeFalse();
});

it('normalizes Mexican phone numbers to ten digits', function () {
    $normalizer = new GdaPhoneNormalizer;

    expect($normalizer->normalize('+52 (55) 1234-5678')->value)->toBe('5512345678')
        ->and($normalizer->normalize('525512345678')->value)->toBe('5512345678')
        ->and($normalizer->normalize('123')->isValid())->toBeFalse();
});

it('normalizes coordinates conservatively', function () {
    $normalizer = new GdaCoordinateNormalizer;

    expect($normalizer->normalize('19,134047', 'latitude')->value)->toBe('19.1340470')
        ->and($normalizer->normalize('19134047', 'latitude')->value)->toBe('19.1340470')
        ->and($normalizer->normalize('-98771021', 'longitude')->value)->toBe('-98.7710210')
        ->and($normalizer->normalize('1.9134047E1', 'latitude')->value)->toBe('19.1340470')
        ->and($normalizer->normalize('451234', 'latitude')->manualReview)->toBeTrue()
        ->and($normalizer->normalize('999', 'latitude')->isValid())->toBeFalse();
});

it('normalizes real OLAB coordinate formats from the GDA workbook', function () {
    $normalizer = new GdaCoordinateNormalizer;

    expect($normalizer->normalize('19348.25', 'latitude')->value)->toBe('19.3482500')
        ->and($normalizer->normalize('-99190.92', 'longitude')->value)->toBe('-99.1909200')
        ->and($normalizer->normalize('20596.26', 'latitude')->value)->toBe('20.5962600')
        ->and($normalizer->normalize('-100372.56', 'longitude')->value)->toBe('-100.3725600')
        ->and($normalizer->normalize('195.907', 'latitude')->value)->toBe('19.5907000')
        ->and($normalizer->normalize('-9925414', 'longitude')->value)->toBe('-99.2541400')
        ->and($normalizer->normalize('19417.79', 'latitude')->value)->toBe('19.4177900')
        ->and($normalizer->normalize('-99101.51', 'longitude')->value)->toBe('-99.1015100')
        ->and($normalizer->normalize('19390.23', 'latitude')->value)->toBe('19.3902300')
        ->and($normalizer->normalize('-99174.03', 'longitude')->value)->toBe('-99.1740300')
        ->and($normalizer->normalize('19438.29', 'latitude')->value)->toBe('19.4382900')
        ->and($normalizer->normalize('-99187862', 'longitude')->value)->toBe('-99.1878620')
        ->and($normalizer->normalize('20655.865', 'latitude')->value)->toBe('20.6558650')
        ->and($normalizer->normalize('-100343049', 'longitude')->value)->toBe('-100.3430490')
        ->and($normalizer->normalize('206121515636866', 'latitude')->isValid())->toBeFalse()
        ->and($normalizer->normalize('-1.003837886349E+16', 'longitude')->isValid())->toBeFalse();
});

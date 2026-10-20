<?php

use App\Support\TaxProfiles\ConstanciaDateParser;

it('parses spanish inscription dates from constancia', function () {
    expect(ConstanciaDateParser::parseForStorage('14 de octubre de 1998'))
        ->toBe('1998-10-14');
});

it('parses numeric dates from constancia', function () {
    expect(ConstanciaDateParser::parseForStorage('14/10/1998'))
        ->toBe('1998-10-14');
});

it('keeps iso dates unchanged', function () {
    expect(ConstanciaDateParser::parseForStorage('2024-01-15'))
        ->toBe('2024-01-15');
});

it('returns null for unparseable dates', function () {
    expect(ConstanciaDateParser::parseForStorage('fecha desconocida'))
        ->toBeNull();
});

<?php

use App\Console\Commands\RunAkubicaUatCommand;

it('authorizes the official QA host and its Forge alias exactly', function (string $url) {
    expect(RunAkubicaUatCommand::isAuthorizedBaseUrl($url))->toBeTrue();
})->with([
    'staging oficial' => 'https://staging.famedic.com.mx',
    'staging con barra final' => 'https://staging.famedic.com.mx/',
    'alias forge' => 'https://famedic-otp.on-forge.com',
]);

it('rejects production and any other host', function (string $url) {
    expect(RunAkubicaUatCommand::isAuthorizedBaseUrl($url))->toBeFalse();
})->with([
    'apex' => 'https://famedic.com.mx',
    'www' => 'https://www.famedic.com.mx',
    'app' => 'https://app.famedic.com.mx',
    'api' => 'https://api.famedic.com.mx',
    'prod' => 'https://prod.famedic.com.mx',
    'qa' => 'https://qa.famedic.com.mx',
    'famedic.mx' => 'https://famedic.mx',
    'http staging' => 'http://staging.famedic.com.mx',
    'staging con ruta' => 'https://staging.famedic.com.mx/api/v1',
    'staging con puerto' => 'https://staging.famedic.com.mx:8443',
    'staging mayúsculas' => 'https://STAGING.famedic.com.mx',
    'sufijo engañoso' => 'https://staging.famedic.com.mx.evil.test',
    'prefijo engañoso' => 'https://evilstaging.famedic.com.mx',
    'otro forge' => 'https://famedic-qa.on-forge.com',
    'vacío' => '',
]);

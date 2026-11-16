<?php

// RC2 B24: the enablement switch is staging-only and defaults to off.
function b24ConfigFor(?string $appEnv, ?string $flag): array
{
    $saved = [getenv('APP_ENV'), getenv('AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED')];
    $set = function (string $k, ?string $v) {
        if ($v === null) { putenv($k); unset($_ENV[$k], $_SERVER[$k]); } else { putenv("$k=$v"); $_ENV[$k] = $_SERVER[$k] = $v; }
    };
    $set('APP_ENV', $appEnv);
    $set('AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED', $flag);
    try {
        return (require config_path('akubica_connectors.php'))['continuity']['medical_services'];
    } finally {
        $set('APP_ENV', $saved[0] === false ? null : $saved[0]);
        $set('AKUBICA_B24_MEDICAL_CONTINUITY_ENABLED', $saved[1] === false ? null : $saved[1]);
    }
}

test('B24 enablement defaults to off in every environment', function () {
    foreach (['staging', 'production', 'local', 'testing'] as $env) {
        expect(b24ConfigFor($env, null))->toBe(['enabled' => false, 'certified' => false]);
    }
});

test('B24 enablement turns on only in staging with the explicit variable', function () {
    expect(b24ConfigFor('staging', 'true'))->toBe(['enabled' => true, 'certified' => true])
        ->and(b24ConfigFor('staging', 'false'))->toBe(['enabled' => false, 'certified' => false])
        ->and(b24ConfigFor('production', 'true'))->toBe(['enabled' => false, 'certified' => false])
        ->and(b24ConfigFor('local', 'true'))->toBe(['enabled' => false, 'certified' => false]);
});

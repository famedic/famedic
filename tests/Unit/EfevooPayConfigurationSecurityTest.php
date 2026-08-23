<?php

use App\Services\EfevooPayService;

test('efevoopay configuration does not ship sensitive defaults', function () {
    $paths = [
        base_path('config/efevoopay.php'),
        base_path('config/efevoo.php'),
    ];

    $sensitiveEnvNames = [
        'EFEVOO_API_USER',
        'EFEVOO_API_KEY',
        'EFEVOO_TOTP_SECRET',
        'EFEVOO_CLAVE',
        'EFEVOO_CLIENTE',
        'EFEVOO_VECTOR',
        'EFEVOO_FIID_COMERCIO',
        'EFEVOOPAY_FIXED_TOKEN',
    ];

    foreach ($paths as $path) {
        $contents = file_get_contents($path);

        foreach ($sensitiveEnvNames as $envName) {
            expect($contents)->not->toMatch("/env\\('{$envName}',\\s*['\"][^'\"]+['\"]/");
        }
    }
});

test('env example uses non production placeholders for efevoopay', function () {
    $contents = file_get_contents(base_path('.env.example'));

    expect($contents)
        ->toContain('EFEVOO_API_URL=https://example.invalid/efevoopay/apiservice')
        ->toContain('EFEVOO_API_KEY=example-api-key')
        ->toContain('EFEVOO_TOTP_SECRET=EXAMPLETOTPSECRET234567')
        ->not->toContain('test-intgapi.efevoopay.com');
});

test('efevoopay service fails closed when required credentials are missing', function () {
    config([
        'efevoopay.api_url' => 'https://example.invalid/efevoopay/apiservice',
        'efevoopay.api_user' => 'example-api-user',
        'efevoopay.api_key' => null,
        'efevoopay.clave' => 'example-encryption-key',
        'efevoopay.vector' => 'example-init-vector',
        'efevoopay.cliente' => 'example-merchant',
        'efevoopay.totp_secret' => 'EXAMPLETOTPSECRET234567',
        'efevoopay.fiid_comercio' => '0000000',
    ]);

    expect(fn () => new EfevooPayService())
        ->toThrow(RuntimeException::class, 'efevoopay.api_key');
});


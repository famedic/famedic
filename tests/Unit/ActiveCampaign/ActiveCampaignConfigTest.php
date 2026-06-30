<?php

use App\Services\ActiveCampaign\ActiveCampaignService;

require_once __DIR__.'/../../../config/activecampaign_env.php';

test('activecampaign config lee flags enabled y coupons_enabled', function () {
    config([
        'services.activecampaign.enabled' => false,
        'services.activecampaign.coupons_enabled' => false,
    ]);

    expect(config('services.activecampaign.enabled'))->toBeFalse();
    expect(config('services.activecampaign.coupons_enabled'))->toBeFalse();

    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
    ]);

    expect(config('services.activecampaign.enabled'))->toBeTrue();
    expect(config('services.activecampaign.coupons_enabled'))->toBeTrue();
});

test('activecampaign config contiene tags finales de nomenclatura §17', function () {
    $tags = config('services.activecampaign.tags');

    expect($tags['credit']['available'])->toBe('FM-Credito-Disponible');
    expect($tags['credit']['expiring'])->toBe('FM-Credito-Por-Vencer');
    expect($tags['credit']['used'])->toBe('FM-Credito-Usado');
    expect($tags['credit']['restored'])->toBe('FM-Credito-Restaurado');
    expect($tags['credit']['revoked'])->toBe('FM-Credito-Revocado');
    expect($tags['credit']['closed'])->toBe('FM-Credito-Cerrado');
    expect($tags['beneficiary']['pending'])->toBe('FM-Beneficiario-Pendiente-Registro');
    expect($tags['benefit']['activated'])->toBe('FM-Beneficio-Activado');
    expect($tags['promo']['validated'])->toBe('FM-Promo-Validada');
    expect($tags['promo']['used'])->toBe('FM-Promo-Usada');
    expect($tags['promo']['abandoned'])->toBe('FM-Promo-Abandonada');
    expect($tags['authorization']['pending'])->toBe('FM-Autorizacion-Pendiente');
});

test('todos los tags §17 en config resuelven a string no vacío', function () {
    $tags = config('services.activecampaign.tags');

    $flat = [
        $tags['credit']['available'],
        $tags['credit']['expiring'],
        $tags['credit']['used'],
        $tags['credit']['restored'],
        $tags['credit']['revoked'],
        $tags['credit']['closed'],
        $tags['beneficiary']['pending'],
        $tags['benefit']['activated'],
        $tags['promo']['validated'],
        $tags['promo']['used'],
        $tags['promo']['abandoned'],
        $tags['authorization']['pending'],
    ];

    foreach ($flat as $tag) {
        expect($tag)->toBeString()->not->toBe('');
    }
});

test('activecampaign config contiene fields finales de nomenclatura', function () {
    $fields = config('services.activecampaign.fields');

    expect($fields)->toHaveKeys([
        'fm_user_id',
        'fm_customer_id',
        'fm_credito_estado',
        'fm_credito_monto',
        'fm_credito_restante',
        'fm_credito_expira_at',
        'fm_credito_compra_minima',
        'fm_credito_campania',
        'fm_credito_tipo',
        'fm_credito_ultimo_uso_at',
        'fm_saldo_total',
        'fm_saldo_aplicable',
        'fm_saldo_condicionado',
        'fm_promo_ultimo_codigo',
        'fm_promo_estado',
        'fm_ultima_compra_lab_at',
    ]);
});

test('fields §17 sin valor en env resuelven a null', function () {
    $fields = config('services.activecampaign.fields');

    foreach ($fields as $value) {
        expect($value)->toBeNull();
    }
});

test('active_campaign_env usa default cuando la variable está ausente', function () {
    $key = 'ACTIVE_CAMPAIGN_TEST_'.uniqid();

    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);

    expect(active_campaign_env($key, 'FM-Default'))->toBe('FM-Default');
});

test('active_campaign_env usa default cuando la variable es string vacío', function () {
    $key = 'ACTIVE_CAMPAIGN_TEST_'.uniqid();

    putenv("{$key}=");
    $_ENV[$key] = '';
    $_SERVER[$key] = '';

    expect(active_campaign_env($key, 'FM-Default'))->toBe('FM-Default');
});

test('active_campaign_env usa default cuando la variable es solo espacios', function () {
    $key = 'ACTIVE_CAMPAIGN_TEST_'.uniqid();

    putenv("{$key}=   ");
    $_ENV[$key] = '   ';
    $_SERVER[$key] = '   ';

    expect(active_campaign_env($key, 'FM-Default'))->toBe('FM-Default');
});

test('active_campaign_env conserva valor real cuando está configurado', function () {
    $key = 'ACTIVE_CAMPAIGN_TEST_'.uniqid();

    putenv("{$key}=FM-Custom-Tag");
    $_ENV[$key] = 'FM-Custom-Tag';
    $_SERVER[$key] = 'FM-Custom-Tag';

    expect(active_campaign_env($key, 'FM-Default'))->toBe('FM-Custom-Tag');
});

test('active_campaign_env sin default devuelve null para valor vacío', function () {
    $key = 'ACTIVE_CAMPAIGN_TEST_'.uniqid();

    putenv("{$key}=");
    $_ENV[$key] = '';
    $_SERVER[$key] = '';

    expect(active_campaign_env($key))->toBeNull();
});

test('active_campaign_env recorta espacios en valores reales', function () {
    $key = 'ACTIVE_CAMPAIGN_TEST_'.uniqid();

    putenv("{$key}=  12345  ");
    $_ENV[$key] = '  12345  ';
    $_SERVER[$key] = '  12345  ';

    expect(active_campaign_env($key))->toBe('12345');
});

test('ActiveCampaignService helpers leen tags y fields por clave', function () {
    config([
        'services.activecampaign.endpoint' => 'https://example.api-us1.com',
        'services.activecampaign.token' => 'test-token',
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
        'services.activecampaign.tags.credit.available' => 'FM-Credito-Disponible',
        'services.activecampaign.fields.fm_saldo_total' => '42',
    ]);

    $service = new ActiveCampaignService;

    expect($service->enabled())->toBeTrue();
    expect($service->couponsEnabled())->toBeTrue();
    expect($service->couponTag('credit.available'))->toBe('FM-Credito-Disponible');
    expect($service->couponField('fm_saldo_total'))->toBe('42');
    expect($service->couponField('fm_missing'))->toBeNull();
});

test('payload de prueba no contiene datos sensibles en logs sanitizados', function () {
    $service = app(\App\Services\ActiveCampaign\ActiveCampaignDispatchService::class);

    $sanitized = $service->sanitizePayloadForLog([
        'email' => 'user@example.com',
        'otp' => '123456',
        'validation_token' => str_repeat('x', 48),
        'authorization_code' => '999999',
        'card_number' => '4111111111111111',
        'nested' => ['cvv' => '123'],
    ]);

    expect($sanitized['email'])->toBe('user@example.com');
    expect($sanitized['otp'])->toBe('[redacted]');
    expect($sanitized['validation_token'])->toBe('[redacted]');
    expect($sanitized['authorization_code'])->toBe('[redacted]');
    expect($sanitized['card_number'])->toBe('[redacted]');
    expect($sanitized['nested']['cvv'])->toBe('[redacted]');
});

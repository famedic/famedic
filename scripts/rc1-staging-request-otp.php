<?php

/**
 * RC1 staging helper: ATIX phone-lookup + login request-code (sin OTP en salida).
 *
 * Variables de entorno obligatorias:
 *   BASE_URL, ATIX_SANDBOX_INTEGRATION_KEY_ID, ATIX_SANDBOX_HMAC_SECRET, RC1_STAGING_PHONE
 *
 * Uso: php scripts/rc1-staging-request-otp.php [--dry-run]
 */

declare(strict_types=1);

const RC1_STAGING_OTP_ATIX_PATH = '/api/v1/integrations/atix/phone-lookups';

const RC1_STAGING_OTP_LOGIN_PATH = '/api/v1/auth/login/request-code';

/** @var list<string> */
const RC1_STAGING_OTP_PRODUCTION_HOSTS = [
    'famedic.com.mx',
    'www.famedic.com.mx',
    'app.famedic.com.mx',
    'api.famedic.com.mx',
    'www.famedic.com',
];

/**
 * @return list<string>
 */
function rc1_staging_otp_required_env_names(): array
{
    return [
        'BASE_URL',
        'ATIX_SANDBOX_INTEGRATION_KEY_ID',
        'ATIX_SANDBOX_HMAC_SECRET',
        'RC1_STAGING_PHONE',
    ];
}

/**
 * @return array<string, string>
 */
function rc1_staging_otp_load_config_from_env(): array
{
    $config = [];
    foreach (rc1_staging_otp_required_env_names() as $name) {
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("BLOCKED: falta variable de entorno {$name}");
        }
        $config[$name] = trim($value);
    }

    $config['BASE_URL'] = rc1_staging_otp_assert_base_url_allowed($config['BASE_URL']);
    $config['RC1_STAGING_PHONE'] = rc1_staging_otp_assert_e164_phone($config['RC1_STAGING_PHONE']);

    return $config;
}

function rc1_staging_otp_assert_base_url_allowed(string $baseUrl): string
{
    $trimmed = rtrim(trim($baseUrl), '/');
    $parsed = parse_url($trimmed);
    if (! is_array($parsed) || ! isset($parsed['host'])) {
        throw new RuntimeException('BLOCKED: BASE_URL invalida');
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    if ($scheme !== 'https') {
        throw new RuntimeException('BLOCKED: BASE_URL debe usar HTTPS');
    }

    $host = strtolower((string) $parsed['host']);
    if (in_array($host, RC1_STAGING_OTP_PRODUCTION_HOSTS, true)) {
        throw new RuntimeException('BLOCKED: BASE_URL apunta a produccion');
    }

    if (str_contains($host, 'famedic.com.mx') && ! str_contains($host, 'staging')) {
        throw new RuntimeException('BLOCKED: BASE_URL apunta a produccion (host famedic.com.mx sin staging)');
    }

    if (! str_contains($host, 'staging')) {
        throw new RuntimeException('BLOCKED: BASE_URL debe ser un host de staging (contener "staging")');
    }

    $path = (string) ($parsed['path'] ?? '');
    if ($path !== '' && ! str_ends_with(rtrim($path, '/'), '/api/v1')) {
        throw new RuntimeException('BLOCKED: BASE_URL debe terminar en /api/v1');
    }

    return $trimmed;
}

function rc1_staging_otp_assert_e164_phone(string $phone): string
{
    $phone = trim($phone);
    if (! preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
        throw new RuntimeException('BLOCKED: RC1_STAGING_PHONE debe estar en formato E.164 (+...)');
    }

    return $phone;
}

function rc1_staging_otp_uuid4(): string
{
    if (class_exists(\Symfony\Component\Uid\Uuid::class)) {
        return (string) \Symfony\Component\Uid\Uuid::v4();
    }

    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * @return array{
 *   conversation_id: string,
 *   channel_event_id: string,
 *   nonce: string,
 *   correlation_id: string,
 *   lookup_idempotency_key: string,
 *   request_code_idempotency_key: string,
 *   timestamp: string
 * }
 */
function rc1_staging_otp_fresh_run_ids(): array
{
    return [
        'conversation_id' => rc1_staging_otp_uuid4(),
        'channel_event_id' => rc1_staging_otp_uuid4(),
        'nonce' => rc1_staging_otp_uuid4(),
        'correlation_id' => rc1_staging_otp_uuid4(),
        'lookup_idempotency_key' => rc1_staging_otp_uuid4(),
        'request_code_idempotency_key' => rc1_staging_otp_uuid4(),
        'timestamp' => (string) time(),
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function rc1_staging_otp_json_body(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar el payload JSON');
    }

    return $json;
}

/**
 * Firma ATIX (identica a Postman RC1 y {@see \App\Services\Api\V1\Integrations\AtixHmacService}).
 */
function rc1_staging_otp_atix_signature(
    string $body,
    string $timestamp,
    string $nonce,
    string $secret,
    string $method = 'POST',
    string $path = RC1_STAGING_OTP_ATIX_PATH,
): string {
    $canonical = implode("\n", [
        strtoupper($method),
        $path,
        $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    return hash_hmac('sha256', $canonical, $secret);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function rc1_staging_otp_atix_headers(
    array $payload,
    string $keyId,
    string $secret,
    string $timestamp,
    string $nonce,
    string $correlationId,
    string $idempotencyKey,
): array {
    $body = rc1_staging_otp_json_body($payload);

    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Integration-Key-Id: '.$keyId,
        'X-Timestamp: '.$timestamp,
        'X-Nonce: '.$nonce,
        'X-Correlation-ID: '.$correlationId,
        'Idempotency-Key: '.$idempotencyKey,
        'X-Signature: '.rc1_staging_otp_atix_signature($body, $timestamp, $nonce, $secret),
    ];
}

/**
 * @param  array<string, string>  $headers
 * @return array{status: int, body: string, headers: array<string, list<string>>}
 */
function rc1_staging_otp_http_post(string $url, string $body, array $headers): array
{
    $hook = $GLOBALS['rc1_staging_otp_http_post_hook'] ?? null;
    if (is_callable($hook)) {
        return $hook($url, $body, $headers);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('No se pudo inicializar cURL');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP fallo: '.$error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $responseBody = substr((string) $raw, $headerSize);

    return [
        'status' => $status,
        'body' => $responseBody,
        'headers' => rc1_staging_otp_parse_response_headers($rawHeaders),
    ];
}

/**
 * @return array<string, list<string>>
 */
function rc1_staging_otp_parse_response_headers(string $rawHeaders): array
{
    $headers = [];
    foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [] as $line) {
        if (! str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $headers[$name][] = trim($value);
    }

    return $headers;
}

/**
 * @return array<string, mixed>|null
 */
function rc1_staging_otp_decode_json(string $body): ?array
{
    if ($body === '') {
        return null;
    }
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

function rc1_staging_otp_mask_phone(string $e164): string
{
    $digits = preg_replace('/\D+/', '', $e164) ?? '';
    if (strlen($digits) < 4) {
        return '***';
    }

    return '***'.substr($digits, -4);
}

function rc1_staging_otp_sanitize_base_url_for_output(string $baseUrl): string
{
    $parsed = parse_url($baseUrl);
    if (! is_array($parsed) || ! isset($parsed['host'])) {
        return '[invalid-base-url]';
    }

    $host = (string) $parsed['host'];
    $path = rtrim((string) ($parsed['path'] ?? ''), '/');

    return 'https://'.$host.$path;
}

function rc1_staging_otp_admin_monitor_url(string $baseUrl): string
{
    $parsed = parse_url($baseUrl);
    $host = is_array($parsed) && isset($parsed['host']) ? (string) $parsed['host'] : 'staging';

    return 'https://'.$host.'/admin/otp-movements-monitor';
}

/**
 * @param  array<string, mixed>|null  $json
 */
function rc1_staging_otp_lookup_context_status(?array $json): string
{
    $status = is_array($json) ? (string) rc1_staging_otp_data_get($json, 'data.recognition_status', '') : '';
    if ($status === 'recognized_unverified') {
        return 'pre_otp_context_ready';
    }
    if ($status === 'unknown') {
        return 'no_account_or_ineligible';
    }

    return $status !== '' ? $status : 'n/a';
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $ids
 * @return array<string, mixed>
 */
function rc1_staging_otp_build_lookup_payload(array $config, array $ids): array
{
    return [
        'phone' => $config['RC1_STAGING_PHONE'],
        'conversation_id' => $ids['conversation_id'],
        'channel_event_id' => $ids['channel_event_id'],
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ];
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $ids
 * @return array<string, mixed>
 */
function rc1_staging_otp_build_request_code_payload(array $config, array $ids): array
{
    return [
        'phone' => $config['RC1_STAGING_PHONE'],
        'conversation_id' => $ids['conversation_id'],
        'channel_event_id' => $ids['channel_event_id'],
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ];
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $ids
 */
function rc1_staging_otp_print_dry_run_summary(array $config, array $ids): void
{
    rc1_staging_otp_echo_line('RC1 staging OTP — dry-run OK (sin requests HTTP)');
    rc1_staging_otp_echo_line('base_url: '.rc1_staging_otp_sanitize_base_url_for_output($config['BASE_URL']));
    rc1_staging_otp_echo_line('phone: '.rc1_staging_otp_mask_phone($config['RC1_STAGING_PHONE']));
    rc1_staging_otp_echo_line('conversation_id: '.$ids['conversation_id']);
    rc1_staging_otp_echo_line('channel_event_id: '.$ids['channel_event_id']);
    rc1_staging_otp_echo_line('correlation_id: '.$ids['correlation_id']);
    rc1_staging_otp_echo_line('lookup_idempotency_key: '.$ids['lookup_idempotency_key']);
    rc1_staging_otp_echo_line('request_code_idempotency_key: '.$ids['request_code_idempotency_key']);
}

function rc1_staging_otp_echo_line(string $line): void
{
    $secret = getenv('ATIX_SANDBOX_HMAC_SECRET');
    if (is_string($secret) && $secret !== '' && str_contains($line, $secret)) {
        throw new RuntimeException('BLOCKED: intento de imprimir secreto ATIX');
    }

    echo $line.PHP_EOL;
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, string>  $ids
 */
function rc1_staging_otp_run(bool $dryRun, array $config, array $ids): int
{
    if ($dryRun) {
        rc1_staging_otp_print_dry_run_summary($config, $ids);

        return 0;
    }

    $lookupPayload = rc1_staging_otp_build_lookup_payload($config, $ids);
    $lookupBody = rc1_staging_otp_json_body($lookupPayload);
    $lookupUrl = $config['BASE_URL'].'/integrations/atix/phone-lookups';

    $lookupHeaders = rc1_staging_otp_atix_headers(
        $lookupPayload,
        $config['ATIX_SANDBOX_INTEGRATION_KEY_ID'],
        $config['ATIX_SANDBOX_HMAC_SECRET'],
        $ids['timestamp'],
        $ids['nonce'],
        $ids['correlation_id'],
        $ids['lookup_idempotency_key'],
    );

    $lookupResponse = rc1_staging_otp_http_post($lookupUrl, $lookupBody, $lookupHeaders);
    $lookupJson = rc1_staging_otp_decode_json($lookupResponse['body']);

    rc1_staging_otp_echo_line('--- ATIX phone-lookup ---');
    rc1_staging_otp_echo_line('http_status: '.$lookupResponse['status']);
    rc1_staging_otp_echo_line('correlation_id: '.$ids['correlation_id']);
    rc1_staging_otp_echo_line('recognition_status: '.(string) rc1_staging_otp_data_get($lookupJson, 'data.recognition_status', 'n/a'));
    rc1_staging_otp_echo_line('context_status: '.rc1_staging_otp_lookup_context_status($lookupJson));
    $lookupExpires = rc1_staging_otp_data_get($lookupJson, 'data.expires_at');
    rc1_staging_otp_echo_line('expires_at: '.(is_string($lookupExpires) && $lookupExpires !== '' ? $lookupExpires : 'n/a'));

    if ($lookupResponse['status'] === 409) {
        rc1_staging_otp_echo_line('error: nonce o idempotency en replay (409). Vuelve a ejecutar el script (UUID nuevos).');

        return 1;
    }

    if ($lookupResponse['status'] !== 202) {
        $code = (string) rc1_staging_otp_data_get($lookupJson, 'error.code', 'unknown');
        rc1_staging_otp_echo_line('error: lookup no aceptado (esperado 202). codigo='.$code);

        return 1;
    }

    $requestPayload = rc1_staging_otp_build_request_code_payload($config, $ids);
    $requestBody = rc1_staging_otp_json_body($requestPayload);
    $requestUrl = $config['BASE_URL'].'/auth/login/request-code';
    $requestHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Correlation-ID: '.$ids['correlation_id'],
        'Idempotency-Key: '.$ids['request_code_idempotency_key'],
    ];

    $requestResponse = rc1_staging_otp_http_post($requestUrl, $requestBody, $requestHeaders);
    $requestJson = rc1_staging_otp_decode_json($requestResponse['body']);

    rc1_staging_otp_echo_line('--- login request-code ---');
    rc1_staging_otp_echo_line('http_status: '.$requestResponse['status']);
    rc1_staging_otp_echo_line('challenge_id: '.(string) rc1_staging_otp_data_get($requestJson, 'data.challenge_id', 'n/a'));
    rc1_staging_otp_echo_line('expires_at: '.(string) rc1_staging_otp_data_get($requestJson, 'data.expires_at', 'n/a'));
    rc1_staging_otp_echo_line('delivery_channel: '.(string) rc1_staging_otp_data_get($requestJson, 'data.channel', 'n/a'));
    rc1_staging_otp_echo_line('destination_masked: '.(string) rc1_staging_otp_data_get($requestJson, 'data.destination_masked', 'n/a'));
    rc1_staging_otp_echo_line('correlation_id: '.$ids['correlation_id']);

    if ($requestResponse['status'] !== 202) {
        $code = (string) rc1_staging_otp_data_get($requestJson, 'error.code', 'unknown');
        rc1_staging_otp_echo_line('error: request-code no aceptado (esperado 202). codigo='.$code);

        return 1;
    }

    $recognition = (string) rc1_staging_otp_data_get($lookupJson, 'data.recognition_status', '');
    if ($recognition === 'unknown') {
        rc1_staging_otp_echo_line('nota: respuesta decoy (telefono no elegible); challenge_id sintetico — no habra OTP real.');
    }

    rc1_staging_otp_echo_line('--- resumen ---');
    rc1_staging_otp_echo_line('base_url: '.rc1_staging_otp_sanitize_base_url_for_output($config['BASE_URL']));
    rc1_staging_otp_echo_line('phone: '.rc1_staging_otp_mask_phone($config['RC1_STAGING_PHONE']));
    rc1_staging_otp_echo_line('conversation_id: '.$ids['conversation_id']);
    rc1_staging_otp_echo_line('channel_event_id: '.$ids['channel_event_id']);
    rc1_staging_otp_echo_line('challenge_id: '.(string) rc1_staging_otp_data_get($requestJson, 'data.challenge_id', 'n/a'));
    rc1_staging_otp_echo_line('expires_at: '.(string) rc1_staging_otp_data_get($requestJson, 'data.expires_at', 'n/a'));
    rc1_staging_otp_echo_line('correlation_id: '.$ids['correlation_id']);
    rc1_staging_otp_echo_line('siguiente_paso: abrir '.rc1_staging_otp_admin_monitor_url($config['BASE_URL']));
    rc1_staging_otp_echo_line('  → buscar challenge_id en el detalle del movimiento');
    rc1_staging_otp_echo_line('  → revelar OTP solo con permiso otp-movements.otp-reveal (staging + flag ON)');
    rc1_staging_otp_echo_line('  → no automatizar login admin ni guardar cookies en este script');

    return 0;
}

function rc1_staging_otp_data_get(mixed $target, ?string $key, mixed $default = null): mixed
{
    if ($key === null) {
        return $target;
    }
    if (! is_array($target)) {
        return $default;
    }
    if (array_key_exists($key, $target)) {
        return $target[$key];
    }
    if (! str_contains($key, '.')) {
        return $default;
    }
    $segment = explode('.', $key);
    foreach ($segment as $part) {
        if (! is_array($target) || ! array_key_exists($part, $target)) {
            return $default;
        }
        $target = $target[$part];
    }

    return $target;
}

function rc1_staging_otp_main(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);

    try {
        $config = rc1_staging_otp_load_config_from_env();
        $ids = rc1_staging_otp_fresh_run_ids();

        return rc1_staging_otp_run($dryRun, $config, $ids);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage().PHP_EOL);

        return 2;
    }
}

if (! defined('RC1_STAGING_OTP_CLI_NO_MAIN')) {
    exit(rc1_staging_otp_main($argv ?? []));
}

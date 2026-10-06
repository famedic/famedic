<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;

require __DIR__ . '/../vendor/autoload.php';

function line(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function section(string $title): void
{
    line();
    line('== ' . $title . ' ==');
}

function status(bool $ok, string $message): void
{
    line(($ok ? '[OK] ' : '[FAIL] ') . $message);
}

function mask(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '(vacio)';
    }

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

function loadDotEnv(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

function setting(string $key, array $dotenv, ?string $default = null): ?string
{
    $env = getenv($key);

    if ($env !== false && $env !== '') {
        return $env;
    }

    return $dotenv[$key] ?? $default;
}

function normalizeRoot(?string $root): string
{
    $root = trim((string) $root, '/');

    return $root;
}

function s3ObjectKey(string $root, string $relativePath): string
{
    $relativePath = ltrim($relativePath, '/');

    return $root !== '' ? $root . '/' . $relativePath : $relativePath;
}

function testDnsAndTcp(string $host): void
{
    $records = dns_get_record($host, DNS_A);
    status($records !== false && count($records) > 0, "DNS {$host}");

    $errorCode = 0;
    $errorMessage = '';
    $socket = @fsockopen('ssl://' . $host, 443, $errorCode, $errorMessage, 8);
    status(is_resource($socket), "TCP 443 {$host}" . (is_resource($socket) ? '' : " ({$errorCode}: {$errorMessage})"));

    if (is_resource($socket)) {
        fclose($socket);
    }
}

function reportAwsError(AwsException $exception): void
{
    line('[AWS ERROR] Code: ' . ($exception->getAwsErrorCode() ?: 'sin codigo'));
    line('[AWS ERROR] Type: ' . ($exception->getAwsErrorType() ?: 'sin tipo'));
    line('[AWS ERROR] Status: ' . ($exception->getStatusCode() ?: 'sin HTTP status'));
    line('[AWS ERROR] Message: ' . $exception->getAwsErrorMessage());
}

$dotenv = loadDotEnv(__DIR__ . '/../.env');

$region = setting('AWS_DEFAULT_REGION', $dotenv, 'us-east-2');
$bucket = $argv[1] ?? setting('AWS_BUCKET', $dotenv);
$root = normalizeRoot(setting('AWS_ROOT', $dotenv));
$accessKey = setting('AWS_ACCESS_KEY_ID', $dotenv);
$secretKey = setting('AWS_SECRET_ACCESS_KEY', $dotenv);
$endpoint = setting('AWS_ENDPOINT', $dotenv);
$usePathStyle = filter_var(setting('AWS_USE_PATH_STYLE_ENDPOINT', $dotenv, 'false'), FILTER_VALIDATE_BOOL);
$filesystemDisk = setting('FILESYSTEM_DISK', $dotenv, 'local');

section('Configuracion detectada');
status(class_exists(StsClient::class), 'AWS SDK disponible');
line('FILESYSTEM_DISK=' . ($filesystemDisk ?: '(vacio)'));
status((bool) $region, 'AWS_DEFAULT_REGION=' . ($region ?: '(vacio)'));
status((bool) $bucket, 'AWS_BUCKET=' . ($bucket ?: '(vacio)'));
line('AWS_ROOT=' . ($root !== '' ? $root : '(sin prefijo)'));
status((bool) $accessKey, 'AWS_ACCESS_KEY_ID=' . mask($accessKey));
status((bool) $secretKey, 'AWS_SECRET_ACCESS_KEY=' . ($secretKey ? 'presente (' . strlen($secretKey) . ' chars)' : '(vacio)'));
line('AWS_ENDPOINT=' . ($endpoint ?: '(no configurado)'));
line('AWS_USE_PATH_STYLE_ENDPOINT=' . ($usePathStyle ? 'true' : 'false'));
line('Ruta efectiva: s3://' . ($bucket ?: '?') . '/' . ($root !== '' ? $root . '/' : ''));

section('Red hacia AWS');
testDnsAndTcp('sts.' . $region . '.amazonaws.com');
testDnsAndTcp('s3.' . $region . '.amazonaws.com');

if ($bucket) {
    testDnsAndTcp($bucket . '.s3.' . $region . '.amazonaws.com');
}

if (! class_exists(StsClient::class) || ! class_exists(S3Client::class)) {
    line();
    status(false, 'No se puede continuar: falta aws/aws-sdk-php en vendor.');
    exit(2);
}

if (! $region || ! $accessKey || ! $secretKey) {
    line();
    status(false, 'No se puede continuar: faltan region, access key o secret key.');
    line('Configura AWS_SECRET_ACCESS_KEY en .env (no se commitea) y vuelve a ejecutar:');
    line('  php scripts/aws-diagnostics.php');
    exit(2);
}

$credentials = [
    'key' => $accessKey,
    'secret' => $secretKey,
];

$baseConfig = [
    'version' => 'latest',
    'region' => $region,
    'credentials' => $credentials,
    'http' => [
        'connect_timeout' => 8,
        'timeout' => 15,
    ],
];

section('STS get-caller-identity');
$expectedAccount = '905418053596';
$expectedArnFragment = 'ods_fmdc';

try {
    $sts = new StsClient($baseConfig);
    $identity = $sts->getCallerIdentity();

    status(true, 'Credenciales validas para STS');
    line('Account: ' . ($identity['Account'] ?? '(sin cuenta)'));
    line('Arn: ' . ($identity['Arn'] ?? '(sin arn)'));
    line('UserId: ' . ($identity['UserId'] ?? '(sin user id)'));

    if (($identity['Account'] ?? null) !== $expectedAccount) {
        status(false, "La cuenta AWS ({$identity['Account']}) no coincide con la esperada ({$expectedAccount})");
    }

    if (! str_contains((string) ($identity['Arn'] ?? ''), $expectedArnFragment)) {
        status(false, "El ARN no contiene '{$expectedArnFragment}'");
    }
} catch (AwsException $exception) {
    status(false, 'STS rechazo la solicitud');
    reportAwsError($exception);
} catch (Throwable $exception) {
    status(false, 'Error general en STS: ' . $exception->getMessage());
}

if (! $bucket) {
    line();
    status(false, 'No hay bucket configurado para probar S3.');
    exit(2);
}

$s3Config = $baseConfig + [
    'use_path_style_endpoint' => $usePathStyle,
];

if ($endpoint) {
    $s3Config['endpoint'] = $endpoint;
}

$s3 = new S3Client($s3Config);

section('S3 bucket');
try {
    $s3->headBucket(['Bucket' => $bucket]);
    status(true, "headBucket {$bucket}");

    $listPrefix = $root !== '' ? $root . '/' : '';
    $result = $s3->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => $listPrefix,
        'MaxKeys' => 5,
    ]);

    status(true, 'listObjectsV2 permitido');
    line('Prefix: ' . ($listPrefix !== '' ? $listPrefix : '(raiz del bucket)'));
    line('KeyCount: ' . ($result['KeyCount'] ?? 0));
} catch (AwsException $exception) {
    status(false, 'S3 rechazo la solicitud de lectura');
    reportAwsError($exception);
} catch (Throwable $exception) {
    status(false, 'Error general en S3: ' . $exception->getMessage());
}

section('S3 lectura/escritura en prefijo');
$probeRelativePath = '.famedic-diagnostics/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';
$probeKey = s3ObjectKey($root, $probeRelativePath);
$probeBody = 'famedic s3 diagnostics ' . gmdate('c');

try {
    $s3->putObject([
        'Bucket' => $bucket,
        'Key' => $probeKey,
        'Body' => $probeBody,
        'ContentType' => 'text/plain',
    ]);
    status(true, "putObject s3://{$bucket}/{$probeKey}");

    $head = $s3->headObject([
        'Bucket' => $bucket,
        'Key' => $probeKey,
    ]);
    status(true, 'headObject del archivo de prueba');
    line('ContentLength: ' . ($head['ContentLength'] ?? '(desconocido)'));

    $read = (string) $s3->getObject([
        'Bucket' => $bucket,
        'Key' => $probeKey,
    ])->get('Body');
    status($read === $probeBody, 'getObject devolvio el contenido esperado');

    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key' => $probeKey,
    ]);
    status(true, 'deleteObject del archivo de prueba');
} catch (AwsException $exception) {
    status(false, 'Prueba de lectura/escritura fallo');
    reportAwsError($exception);
} catch (Throwable $exception) {
    status(false, 'Error general en prueba de lectura/escritura: ' . $exception->getMessage());
}

section('Laravel Storage (filesystems.default)');
try {
    $app = require __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $diskName = config('filesystems.default');
    $disk = Illuminate\Support\Facades\Storage::disk($diskName);
    $laravelRelativePath = '.famedic-diagnostics/laravel-' . gmdate('Ymd-His') . '.txt';
    $laravelBody = 'laravel storage diagnostics ' . gmdate('c');

    line('Disco activo: ' . $diskName);

    if ($diskName !== 's3') {
        status(false, 'FILESYSTEM_DISK no es s3; la app seguiria usando almacenamiento local.');
        line('En produccion define FILESYSTEM_DISK=s3.');
    }

    $disk->put($laravelRelativePath, $laravelBody);
    status(true, "Storage::put({$laravelRelativePath})");

    $exists = $disk->exists($laravelRelativePath);
    status($exists, 'Storage::exists()');

    $fetched = $disk->get($laravelRelativePath);
    status($fetched === $laravelBody, 'Storage::get()');

    $disk->delete($laravelRelativePath);
    status(true, 'Storage::delete()');
} catch (Throwable $exception) {
    status(false, 'Laravel Storage fallo: ' . $exception->getMessage());
}

line();
status(true, 'Diagnostico finalizado');

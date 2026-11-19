<?php

// RC2 rc2-NEWMAN certification guard (Batch 1 rev7, contract 2b6672e6). B1 (IT-0031): these tests EXECUTE the
// collection-level prerequest script in node with a Postman sandbox double and assert its security BEHAVIOUR
// (block = failing "GUARDA" assertion + skipRequest + exception; skip = SKIPPED_PRECONDITION without sending;
// send = request allowed), instead of looking for literal strings of a given revision.
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

const RC2_GUARD_SHA = '2b6672e6f239dc92105a29e7af1e50c618641455';

function rc2NewmanDir(): string
{
    return dirname(__DIR__, 3).'/docs/Akubica/v1.3.0';
}

/** @return list<string> the complete collection-level prerequest script */
function rc2NewmanGuardLines(): array
{
    $collection = json_decode(file_get_contents(rc2NewmanDir().'/Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json'), true, 512, JSON_THROW_ON_ERROR);
    $pre = array_values(array_filter($collection['event'] ?? [], fn (array $e): bool => $e['listen'] === 'prerequest'));
    expect($pre)->toHaveCount(1);

    return $pre[0]['script']['exec'];
}

/**
 * Runs the guard for one request.
 *
 * @param  array<string, mixed>  $variables  effective values (pm.variables: local > data > environment > collection)
 * @param  array<string, mixed>  $environment  pm.environment values
 * @return array{outcome: string, message: string|null, failed: list<string>, headers: array<string, string>}
 */
function rc2RunGuard(string $url, array $variables, array $environment, string $method = 'GET', string $name = 'R01 lectura', string $description = ''): array
{
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        test()->markTestSkipped('node no disponible para ejecutar la guarda de la colección');
    }
    $harness = <<<'JS'
const spec = JSON.parse(process.argv[2]);
const guard = require('fs').readFileSync(process.argv[3], 'utf8');
let skipped = false; const failed = []; const headers = {}; const local = {};
const lookup = (k) => (k in local ? local[k] : spec.variables[k]);
const replaceIn = (s) => String(s).replace(/\{\{([^{}]+)\}\}/g, (m, k) => (k === '$guid' ? '00000000-0000-4000-8000-000000000000' : (lookup(k) === undefined ? m : String(lookup(k)))));
const pm = {
  info: { requestName: spec.name },
  request: { description: spec.description, method: spec.method, url: { toString: () => spec.url },
    headers: { upsert: (h) => { headers[h.key] = h.value; }, has: (k) => k in headers } },
  variables: { get: lookup, set: (k, v) => { local[k] = v; }, replaceIn },
  environment: { get: (k) => spec.environment[k] },
  execution: { skipRequest: () => { skipped = true; } },
  test: (n, fn) => { try { fn(); } catch (e) { failed.push(n); } },
};
const out = (outcome, message) => console.log(JSON.stringify({ outcome, message, failed, headers }));
try {
  new Function('pm', 'console', guard)(pm, { log: () => {} });
  out(skipped ? 'SKIPPED' : 'SENT', null);
} catch (e) {
  out(skipped ? 'BLOCKED' : 'THREW_WITHOUT_SKIP', e.message);
}
JS;
    $dir = sys_get_temp_dir().'/rc2-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/harness.js', $harness);
    file_put_contents($dir.'/guard.js', implode("\n", rc2NewmanGuardLines()));
    try {
        $process = new Process([$node, $dir.'/harness.js', json_encode(compact('url', 'variables', 'environment', 'method', 'name', 'description'), JSON_THROW_ON_ERROR), $dir.'/guard.js']);
        $process->mustRun();

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        @unlink($dir.'/harness.js');
        @unlink($dir.'/guard.js');
        @rmdir($dir);
    }
}

/** Valid QA context: closed host list, release sha, no write flags. */
function rc2GuardEnv(array $overrides = []): array
{
    return array_merge(['allowed_hosts' => 'staging.famedic.com.mx,famedic-otp.on-forge.com', 'expected_release_sha' => RC2_GUARD_SHA, 'run_id' => 'pest'], $overrides);
}

function rc2ExpectBlocked(array $r, string $code): void
{
    expect($r['outcome'])->toBe('BLOCKED')
        ->and($r['message'])->toStartWith('GUARDA '.$code)
        ->and($r['failed'])->toHaveCount(1)
        ->and($r['failed'][0])->toStartWith('GUARDA '.$code)
        ->and($r['headers'])->not->toHaveKey('X-Correlation-ID');
}

it('points the environment template at the official QA host and keeps the alias allowed', function () {
    $env = json_decode(file_get_contents(rc2NewmanDir().'/Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json'), true, 512, JSON_THROW_ON_ERROR);
    $values = collect($env['values'])->pluck('value', 'key');
    expect($values['base_url'])->toBe('https://staging.famedic.com.mx/api/v1')
        ->and($values['web_base_url'])->toBe('https://staging.famedic.com.mx')
        ->and($values['allowed_hosts'])->toBe('staging.famedic.com.mx,famedic-otp.on-forge.com');
});

it('sends a read to the official QA host or the Forge alias, with correlation id', function (string $baseUrl) {
    $r = rc2RunGuard('{{base_url}}/orders', rc2GuardEnv(['base_url' => $baseUrl]), rc2GuardEnv());
    expect($r['outcome'])->toBe('SENT')->and($r['failed'])->toBe([])
        ->and($r['headers']['X-Correlation-ID'] ?? '')->toStartWith('rc2-pest-');
})->with([
    'staging oficial' => 'https://staging.famedic.com.mx/api/v1',
    'alias forge' => 'https://famedic-otp.on-forge.com/api/v1',
]);

it('blocks production and look-alike hosts before sending, even when listed in allowed_hosts', function (string $host) {
    $env = rc2GuardEnv(['allowed_hosts' => "staging.famedic.com.mx,famedic-otp.on-forge.com,{$host}", 'base_url' => "https://{$host}/api/v1"]);
    rc2ExpectBlocked(rc2RunGuard('{{base_url}}/orders', $env, $env), 'HOST_NO_AUTORIZADO');
})->with([
    'famedic.com.mx', 'www.famedic.com.mx', 'app.famedic.com.mx', 'api.famedic.com.mx', 'prod.famedic.com.mx', 'qa.famedic.com.mx',
    'beta.famedic.com.mx', 'STAGING.famedic.com.mx', 'www.famedic.com.mx.', 'www.famedic.com.mx:443', 'staging.famedic.com.mx:443',
    'famedic.mx', 'www.famedic.mx', 'staging.famedic.com.mx.evil.test', 'example.test',
]);

it('uses the EFFECTIVE url: a data/local base_url pointing to production is blocked even if the environment is QA', function () {
    $r = rc2RunGuard('{{base_url}}/orders', rc2GuardEnv(['base_url' => 'https://www.famedic.com.mx/api/v1']), rc2GuardEnv(['base_url' => 'https://staging.famedic.com.mx/api/v1']));
    rc2ExpectBlocked($r, 'HOST_NO_AUTORIZADO');
});

it('requires https', function () {
    rc2ExpectBlocked(rc2RunGuard('http://staging.famedic.com.mx/api/v1/orders', rc2GuardEnv(), rc2GuardEnv()), 'HTTPS_REQUERIDO');
});

it('lets allowed_hosts only restrict the closed list, never widen it', function () {
    $wide = rc2GuardEnv(['allowed_hosts' => 'staging.famedic.com.mx,example.test']);
    rc2ExpectBlocked(rc2RunGuard('https://staging.famedic.com.mx/api/v1/orders', $wide, $wide), 'ALLOWED_HOSTS_FUERA_DE_LISTA_CERRADA');

    $narrow = rc2GuardEnv(['allowed_hosts' => 'famedic-otp.on-forge.com']);
    rc2ExpectBlocked(rc2RunGuard('https://staging.famedic.com.mx/api/v1/orders', $narrow, $narrow), 'HOST_NO_PERMITIDO');
});

it('blocks unresolved url variables and a missing or malformed release sha', function () {
    rc2ExpectBlocked(rc2RunGuard('{{base_url}}/orders/{{order_id}}', rc2GuardEnv(['base_url' => 'https://staging.famedic.com.mx/api/v1']), rc2GuardEnv()), 'URL_CON_VARIABLES_SIN_RESOLVER');
    foreach (['', 'abc', strtoupper(RC2_GUARD_SHA)] as $sha) {
        $env = rc2GuardEnv(['expected_release_sha' => $sha]);
        rc2ExpectBlocked(rc2RunGuard('https://staging.famedic.com.mx/api/v1/orders', $env, $env), 'RELEASE_SHA_REQUERIDO');
    }
});

it('skips a positive write unless qa_writes_authorized is exactly "1" in the effective value and the environment', function (mixed $effective, mixed $environment, string $expected) {
    $vars = rc2GuardEnv(['qa_writes_authorized' => $effective]);
    $env = rc2GuardEnv(['qa_writes_authorized' => $environment]);
    $r = rc2RunGuard('https://staging.famedic.com.mx/api/v1/cart/items', $vars, $env, 'POST', 'B19 alta');
    expect($r['outcome'])->toBe($expected)->and($r['failed'])->toBe([]);
    if ($expected === 'SENT') {
        expect($r['headers'])->toHaveKey('Idempotency-Key');
    }
})->with([
    'autorizada' => ['1', '1', 'SENT'],
    'ausente' => [null, null, 'SKIPPED'],
    'cero' => ['0', '0', 'SKIPPED'],
    'numero 1' => [1, 1, 'SKIPPED'],
    'espacio' => [' 1', ' 1', 'SKIPPED'],
    'true' => ['true', 'true', 'SKIPPED'],
    'sólo efectiva' => ['1', '0', 'SKIPPED'],
    'sólo environment' => ['0', '1', 'SKIPPED'],
]);

it('treats an unlisted write as positive and keeps the negative-write flag of B25', function () {
    $r = rc2RunGuard('https://staging.famedic.com.mx/api/v1/cart/items', rc2GuardEnv(), rc2GuardEnv(), 'DELETE', 'Z99 escritura no listada');
    expect($r['outcome'])->toBe('SKIPPED');

    $off = rc2RunGuard('https://staging.famedic.com.mx/api/v1/checkout/payment-link', rc2GuardEnv(), rc2GuardEnv(), 'POST', 'B25 negativo');
    $on = rc2GuardEnv(['leo_negative_writes_authorized' => '1']);
    $sent = rc2RunGuard('https://staging.famedic.com.mx/api/v1/checkout/payment-link', $on, $on, 'POST', 'B25 negativo');
    expect($off['outcome'])->toBe('SKIPPED')->and($sent['outcome'])->toBe('SENT');
});

it('skips a request whose declared precondition is missing and never sends it', function () {
    $r = rc2RunGuard('https://staging.famedic.com.mx/api/v1/orders/{{fixture_order_id}}', rc2GuardEnv(), rc2GuardEnv(), 'GET', 'S09 pedido', 'requires: fixture_order_id');
    expect($r['outcome'])->toBe('SKIPPED')->and($r['failed'])->toBe([]);

    $flag = rc2RunGuard('https://staging.famedic.com.mx/api/v1/orders', rc2GuardEnv(['leo_batch2_contract' => 'true']), rc2GuardEnv(['leo_batch2_contract' => 'true']), 'GET', 'S09 pedido', 'requires: leo_batch2_contract');
    expect($flag['outcome'])->toBe('SKIPPED');
});

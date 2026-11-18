<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function rc2NewmanDir(): string
{
    return dirname(__DIR__, 3).'/docs/Akubica/v1.3.0';
}

/** @return list<string> */
function rc2NewmanGuardLines(): array
{
    $collection = json_decode(file_get_contents(rc2NewmanDir().'/Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json'), true, 512, JSON_THROW_ON_ERROR);
    $pre = array_values(array_filter($collection['event'], fn (array $e): bool => $e['listen'] === 'prerequest'));
    $lines = $pre[0]['script']['exec'];
    $end = null;
    foreach ($lines as $i => $line) {
        if (str_contains($line, 'HOST_PRODUCCION_BLOQUEADO')) {
            $end = $i;
        }
    }

    return array_slice($lines, 0, $end + 1);
}

function rc2NewmanGuard(string $baseUrl, string $allowedHosts): string
{
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        test()->markTestSkipped('node no disponible para evaluar la guarda de la colección');
    }
    $script = 'const env = JSON.parse(process.argv[1]); let skipped = false; const failed = [];'
        .' const pm = { environment: { get: (k) => env[k] }, execution: { skipRequest: () => { skipped = true; } }, test: (n, fn) => { try { fn(); } catch (e) { failed.push(n); } } };'
        ."\ntry {\n".implode("\n", rc2NewmanGuardLines())."\nconsole.log(skipped ? 'OK_PERO_OMITIDO' : 'OK');\n} catch (e) { console.log(e.message + (skipped ? ' [request omitido]' : ' [REQUEST NO OMITIDO]') + (failed.length ? '' : ' [SIN ASERCION FALLIDA]')); }";
    $process = new Process([$node, '-e', $script, json_encode(['base_url' => $baseUrl, 'allowed_hosts' => $allowedHosts])]);
    $process->mustRun();

    return trim($process->getOutput());
}

it('keeps the production guard and allowlists only the official QA host', function () {
    $guard = implode("\n", rc2NewmanGuardLines());
    expect($guard)
        ->toContain("const QA_OFFICIAL_HOSTS = ['staging.famedic.com.mx'];")
        ->toContain("const guardFail = (msg) => { pm.test('GUARDA ' + msg, () => { throw new Error(msg); }); pm.execution.skipRequest(); throw new Error(msg); };")
        ->toContain("guardFail('HOST_PRODUCCION_BLOQUEADO')")
        ->toContain("guardFail('HOST_NO_PERMITIDO: ' + host)")
        ->not->toContain("throw new Error('HOST_")
        ->toContain('famedic\\.com\\.mx$')
        ->toContain('famedic\\.mx$');
});

it('points the environment template at the official QA host and keeps the alias allowed', function () {
    $env = json_decode(file_get_contents(rc2NewmanDir().'/Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json'), true, 512, JSON_THROW_ON_ERROR);
    $values = collect($env['values'])->pluck('value', 'key');
    expect($values['base_url'])->toBe('https://staging.famedic.com.mx/api/v1')
        ->and($values['web_base_url'])->toBe('https://staging.famedic.com.mx')
        ->and($values['allowed_hosts'])->toBe('staging.famedic.com.mx,famedic-otp.on-forge.com');
});

it('lets the official QA host and the Forge alias through the guard', function (string $baseUrl) {
    expect(rc2NewmanGuard($baseUrl, 'staging.famedic.com.mx,famedic-otp.on-forge.com'))->toBe('OK');
})->with([
    'staging oficial' => 'https://staging.famedic.com.mx/api/v1',
    'alias forge' => 'https://famedic-otp.on-forge.com/api/v1',
]);

it('blocks production famedic hosts even when listed in allowed_hosts', function (string $host) {
    expect(rc2NewmanGuard("https://{$host}/api/v1", "staging.famedic.com.mx,famedic-otp.on-forge.com,{$host}"))
        ->toBe('HOST_PRODUCCION_BLOQUEADO [request omitido]');
})->with([
    'famedic.com.mx',
    'www.famedic.com.mx',
    'app.famedic.com.mx',
    'api.famedic.com.mx',
    'prod.famedic.com.mx',
    'qa.famedic.com.mx',
    'beta.famedic.com.mx',
    'STAGING.famedic.com.mx',
    'www.famedic.com.mx.',
    'www.famedic.com.mx:443',
    'staging.famedic.com.mx:443',
    'famedic.mx',
    'www.famedic.mx',
]);

it('rejects hosts outside allowed_hosts', function () {
    expect(rc2NewmanGuard('https://example.test/api/v1', 'staging.famedic.com.mx'))->toBe('HOST_NO_PERMITIDO: example.test [request omitido]');
});

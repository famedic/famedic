#!/usr/bin/env python3
"""Phase 2B-1 diagnostic tests only: no installs, real env, persistent DB or policy changes."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / 'docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml'
EXPECTED_SHA = '523a566bab22fffb4c0ad8fbb29d79cc4823e99769494b1fd135f6639094f9a4'
# Existing ATIX index repair queries MySQL information_schema, unavailable on
# SQLite. This unrelated migration is explicitly excluded from the TEST COPY.
# No repository migration is changed and no ATIX schema compatibility is claimed.
EXCLUDED = ['2026_10_21_142000_relax_atix_operation_unique_indexes.php']


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def php_string(value):
    return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    if args.report.exists() or args.report.is_symlink():
        raise SystemExit('Existing evidence will not be overwritten')
    if digest(CONTRACT) != EXPECTED_SHA:
        raise SystemExit('Approved contract hash mismatch; no test started')
    runtime = Path(tempfile.mkdtemp(prefix='famedic-phase2b1-'))
    for subdir in ['bootstrap', 'storage/logs', 'storage/framework/views', 'database/migrations']:
        (runtime / subdir).mkdir(parents=True, exist_ok=True)
    (runtime / '.env.synthetic').write_text('# Empty synthetic environment. No real credentials.\n')
    for migration in (ROOT / 'database/migrations').iterdir():
        if migration.is_file() and migration.name not in EXCLUDED:
            shutil.copyfile(migration, runtime / 'database/migrations' / migration.name)
    bootstrap = "<?php\n$app = require " + php_string(ROOT / 'bootstrap/app.php') + ";\n"
    bootstrap += "$app->useEnvironmentPath(" + php_string(runtime) + ");\n"
    bootstrap += "$app->loadEnvironmentFrom('.env.synthetic');\n"
    bootstrap += "$app->useStoragePath(" + php_string(runtime / 'storage') + ");\n"
    bootstrap += "$app->useDatabasePath(" + php_string(runtime / 'database') + ");\n"
    bootstrap += """$app->afterBootstrapping(\\Illuminate\\Foundation\\Bootstrap\\LoadConfiguration::class, function ($app) {
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite.database', ':memory:');
    $app['config']->set('services.activecampaign.endpoint', 'https://synthetic.invalid');
    $app['config']->set('services.activecampaign.token', 'synthetic-not-a-credential');
});
$app->booted(function () { \\Illuminate\\Support\\Facades\\Http::preventStrayRequests(); });
$app->afterBootstrapping(\\Illuminate\\Foundation\\Bootstrap\\BootProviders::class, function ($app) {
    if (getenv('FAMEDIC_PHASE2B1_PREPARE_SCHEMA') === '1') {
        // Existing isolated suite assumes its schema is provisioned. Provision
        // only its in-memory connection, never a file/MySQL database.
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \\RuntimeException('Unsafe isolated schema target');
        }
        $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->call('migrate', ['--force' => true]);
    }
});
return $app;
"""
    (runtime / 'bootstrap/app.php').write_text(bootstrap)
    xml = ET.parse(ROOT / 'phpunit.xml')
    root = xml.getroot()
    root.set('cacheDirectory', str(runtime / 'phpunit-cache'))
    root.set('bootstrap', str(ROOT / 'vendor/autoload.php'))
    for suite in root.find('testsuites'):
        for directory in suite:
            directory.text = str(ROOT / directory.text)
    overrides = {
        'APP_BASE_PATH': str(runtime), 'APP_ENV': 'testing',
        'APP_KEY': 'base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=',
        'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:', 'DB_HOST': '',
        'DB_USERNAME': '', 'DB_PASSWORD': '', 'LOG_CHANNEL': 'single',
        'SCOUT_DRIVER': 'null', 'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'array',
        'FILESYSTEM_DISK': 'local', 'FAMEDIC_PHASE2B1_EPHEMERAL': '1',
        'APP_CONFIG_CACHE': str(runtime / 'config-cache.php'),
        'APP_ROUTES_CACHE': str(runtime / 'routes-cache.php'),
        'APP_PACKAGES_CACHE': str(runtime / 'packages.php'),
        'APP_SERVICES_CACHE': str(runtime / 'services.php'),
        'FAMEDIC_PHASE2B1_OBSERVATIONS': str(runtime / 'observations.jsonl'),
    }
    for name, value in overrides.items():
        php = root.find('php')
        element = php.find("env[@name='" + name + "']")
        if element is None:
            element = ET.SubElement(php, 'env', {'name': name})
        element.set('value', value)
        element.set('force', 'true')
    configuration = runtime / 'phpunit.xml'
    xml.write(configuration, encoding='utf-8', xml_declaration=True)
    # A clean child environment prevents shell exports and credentials leaking
    # into tests. Application env/config/routes/package caches are isolated.
    env = {'HOME': str(runtime), 'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8',
           'APP_BASE_PATH': str(runtime), 'APP_ENV': 'testing',
           'PYTHONDONTWRITEBYTECODE': '1', 'PYTHONNOUSERSITE': '1'}
    php = ['php', '-d', 'allow_url_fopen=0', '-d',
           'disable_functions=fsockopen,pfsockopen,stream_socket_client,socket_connect',
           str(ROOT / 'vendor/bin/pest'), '--configuration', str(configuration),
           '--colors=never', '--do-not-cache-result', '--display-warnings']
    regression_files = [
        'AkubicaAuthTest.php', 'AkubicaLoginOtpP0a4Test.php',
        'AkubicaSanctumTokenExpirationP0c1Test.php', 'AkubicaIdempotencyP1Test.php',
        'AkubicaCartCouponTest.php', 'AkubicaCartCatalogTest.php',
        'AkubicaCheckoutPreparationTest.php', 'AkubicaCheckoutPaymentLinkTest.php',
        'AkubicaMedicalServicesCartM2BTest.php', 'AkubicaMedicalServicesCartM2CTest.php',
        'AkubicaErrorsCorrelationP1a6Test.php',
    ]
    commands = {
        'acceptance': php + [str(ROOT / 'tests/Acceptance/Api/V1'), '--log-junit', str(runtime / 'acceptance.xml')],
        'laravel_regressions': php + [str(ROOT / 'tests/Feature/Api/V1' / f) for f in regression_files]
            + ['--log-junit', str(runtime / 'laravel_regressions.xml')],
        'medical_checkout_isolated': php + [str(ROOT / 'tests/Feature/Api/V1/AkubicaMedicalCheckoutPrepareRc1IsolatedTest.php'),
            '--log-junit', str(runtime / 'medical_checkout_isolated.xml')],
        'python': ['/usr/bin/python3', '-B', '-m', 'unittest', 'discover', '-s', 'scripts/tests', '-p', 'test_*.py', '-q'],
        'node': ['node', '--test', 'scripts/tests/test-newman-secure.cjs'],
        'local_contract': ['/usr/bin/python3', '-B', 'scripts/validate-akubica-rc1-artifacts.py',
                           '--report', str(runtime / 'local-contract.json')],
        'diff_check': ['git', 'diff', '--check'],
    }
    receipt = {'phase': '2B-1', 'execution_id': 'phase2b1-' + str(uuid.uuid4()),
               'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
               'result': 'BLOCKED', 'runtime_directory': str(runtime),
               'reason': 'Identity issuance/binding/channel and D08 are not approved; no server authorization policy installed',
               'candidate_sha256': EXPECTED_SHA, 'dependencies_downloaded': False,
               'production_code_changed': False, 'database': 'sqlite :memory:',
               'existing_migrations_excluded_from_test_copy': EXCLUDED, 'commands': {}}
    for name, command in commands.items():
        command_env = dict(env)
        if name == 'medical_checkout_isolated':
            command_env['FAMEDIC_PHASE2B1_PREPARE_SCHEMA'] = '1'
        result = subprocess.run(command, cwd=ROOT, env=command_env, capture_output=True, text=True)
        logfile = runtime / (name + '.log')
        logfile.write_text(result.stdout + result.stderr)
        entry = {'command': command, 'returncode': result.returncode,
                 'log_path': str(logfile), 'log_sha256': digest(logfile)}
        junit = runtime / (name + '.xml')
        if junit.exists():
            cases = []
            for case in ET.parse(junit).getroot().iter('testcase'):
                child_types = {child.tag for child in case}
                state = 'ERROR' if 'error' in child_types else 'FAIL' if 'failure' in child_types else 'BLOCKED' if 'skipped' in child_types else 'PASS'
                cases.append({'name': case.get('name'), 'class': case.get('classname'), 'result': state})
            entry['cases'] = cases
            entry['counts'] = {state: sum(case['result'] == state for case in cases) for state in ['PASS', 'FAIL', 'ERROR', 'BLOCKED']}
            entry['junit_sha256'] = digest(junit)
        receipt['commands'][name] = entry
        print(json.dumps({'check': name, 'returncode': result.returncode, 'counts': entry.get('counts')}), flush=True)
    observation_path = runtime / 'observations.jsonl'
    receipt['observations'] = [json.loads(line) for line in observation_path.read_text().splitlines()] if observation_path.exists() else []
    receipt['candidate_unchanged'] = digest(CONTRACT) == EXPECTED_SHA
    receipt['finished_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
    with args.report.open('x') as handle:
        handle.write(json.dumps(receipt, indent=2, ensure_ascii=False) + '\n')
    print(json.dumps({'result': receipt['result'], 'report': str(args.report)}), flush=True)
    return 3


if __name__ == '__main__':
    raise SystemExit(main())

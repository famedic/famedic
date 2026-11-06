"""Synthetic-only tests: no Laravel, database, HTTP or production fixtures."""
import base64
import contextlib
import copy
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
import zipfile

SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))
from evidence_security import (Scanner, UnsafeEvidence, project_newman, project_junit,
                               scan_main, strict_json, MAX_DEPTH)

spec_module = importlib.util.spec_from_file_location('release_builder', SCRIPTS / 'build-akubica-v1.3.0-rc1.1-entrega.py')
builder = importlib.util.module_from_spec(spec_module)
spec_module.loader.exec_module(builder)

SPEC = {'paths': {'/cart': {'get': {'responses': {'404': {'description': 'CART_ITEM_NOT_FOUND'}}}},
                  '/cart/items/{cart_item_id}': {'delete': {'responses': {}}}}}
# Deliberately fake canaries, never read from operational reports or environments.
CANARY = 'synthetic-private-canary'


def raw_report():
    return {'environment': {'values': [{'key': 'otp_code', 'value': '4321'}]},
            'run': {'executions': [{'item': {'name': CANARY},
                'request': {'method': 'GET', 'url': 'https://fixture.invalid/api/v1/cart',
                            'header': [{'key': 'Authorization', 'value': CANARY}]},
                'response': {'code': 200, 'responseTime': 12.5, 'stream': '{}'},
                'assertions': [{'assertion': CANARY}, {'assertion': CANARY, 'error': {'message': CANARY}}]}]}}


def scan(data, suffix='.json'):
    scanner = Scanner(); scanner.blob(data, suffix)
    return scanner.findings


def zip_bytes(entries):
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, value in entries:
            archive.writestr(name, value)
    return buffer.getvalue()


class ProjectionTests(unittest.TestCase):
    def projected(self, report):
        result = project_newman(report, SPEC)
        self.assertNotIn(CANARY, json.dumps(result))
        self.assertFalse(scan(json.dumps(result).encode()))
        self.assertEqual(set(result), {'execution_id', 'cases'})
        return result

    def test_authorization_key_value_regression(self):
        self.projected(raw_report())

    def test_embedded_json_regression(self):
        report = raw_report()
        report['run']['executions'][0]['request']['body'] = {'raw': json.dumps({'token': CANARY})}
        self.projected(report)

    def test_buffer_regression(self):
        report = raw_report()
        report['run']['executions'][0]['response']['stream'] = {'type': 'Buffer', 'data': list(json.dumps({'token': CANARY}).encode())}
        self.projected(report)

    def test_pii_regression(self):
        report = raw_report()
        report['run']['executions'][0]['response']['stream'] = json.dumps({'email': CANARY, 'name': CANARY, 'phone': CANARY})
        self.projected(report)

    def test_short_otp_regression(self):
        report = raw_report()
        report['run']['executions'][0]['request']['body'] = {'raw': json.dumps({'code': '4321'})}
        self.assertNotIn('4321', json.dumps(self.projected(report)))

    def test_preserves_failure_and_skip_without_messages(self):
        report = raw_report()
        report['run']['executions'][0]['assertions'].append({'skipped': True})
        results = self.projected(report)['cases'][0]['assertions']
        self.assertEqual([a['result'] for a in results], ['PASS', 'FAIL', 'SKIP'])

    def test_route_is_parameterized_and_query_removed(self):
        report = raw_report(); entry = report['run']['executions'][0]
        entry['request'].update(method='DELETE', url='https://fixture.invalid/api/v1/cart/items/123?email=' + CANARY)
        self.assertEqual(self.projected(report)['cases'][0]['route'], '/cart/items/{cart_item_id}')

    def test_error_code_must_be_declared_not_just_uppercase(self):
        report = raw_report(); response = report['run']['executions'][0]['response']
        response.update(code=404, stream=json.dumps({'error': {'code': 'CART_ITEM_NOT_FOUND'}}))
        self.assertEqual(self.projected(report)['cases'][0]['error_code'], 'CART_ITEM_NOT_FOUND')
        response['stream'] = json.dumps({'error': {'code': 'SYNTHETIC_UNDECLARED'}})
        with self.assertRaises(UnsafeEvidence): project_newman(report, SPEC)

    def test_unknown_route_body_buffer_and_missing_assertions_block(self):
        for mutation in ('url', 'body', 'buffer', 'assertions'):
            with self.subTest(case=mutation):
                report = raw_report(); entry = report['run']['executions'][0]
                if mutation == 'url': entry['request']['url'] = '/unmapped/' + CANARY
                if mutation == 'body': entry['response']['stream'] = '<html>unknown</html>'
                if mutation == 'buffer': entry['response']['stream'] = {'type': 'Buffer', 'data': [256]}
                if mutation == 'assertions': entry['assertions'] = []
                with self.assertRaises((UnsafeEvidence, ValueError)): project_newman(report, SPEC)

    def test_unmapped_script_failure_blocks(self):
        report = raw_report(); report['run']['executions'][0]['assertions'] = [{}]
        report['run']['failures'] = [{'error': {'message': CANARY}}]
        with self.assertRaises(UnsafeEvidence): project_newman(report, SPEC)

    def test_nan_duplicate_keys_block(self):
        for text in ('{"x": NaN}', '{"x": 1, "x": 2}'):
            with self.assertRaises(UnsafeEvidence): strict_json(text)

    def test_junit_removes_names_messages_stack_properties_and_output(self):
        raw = ('<testsuites failures="1" errors="1" skipped="1"><testsuite name="' + CANARY + '">'
               '<properties><property name="email" value="' + CANARY + '"/></properties>'
               '<testcase name="' + CANARY + '" time="0.1"><failure message="' + CANARY + '">' + CANARY + '</failure></testcase>'
               '<testcase><error type="' + CANARY + '">' + CANARY + '</error></testcase>'
               '<testcase><skipped/></testcase><system-out>' + CANARY + '</system-out></testsuite></testsuites>').encode()
        projected = project_junit(raw)
        self.assertNotIn(CANARY.encode(), projected)
        root = ET.fromstring(projected)
        self.assertEqual(root.get('failures'), '1'); self.assertEqual(root.get('errors'), '1')
        self.assertFalse(scan(projected, '.xml'))

    def test_junit_unsupported_dtd_and_unmapped_errors_block(self):
        for raw in (b'<unknown/>', b'<!DOCTYPE x><testsuite/>', b'<testsuite><custom/></testsuite>',
                    b'<testsuite failures="1"><testcase/></testsuite>'):
            with self.assertRaises((UnsafeEvidence, ValueError)): project_junit(raw)

    def test_utf16_dtd_cannot_bypass_xml_guard(self):
        payload = '<!DOCTYPE x><testsuite><testcase/></testsuite>'.encode('utf-16')
        with self.assertRaises((UnsafeEvidence, UnicodeError)): project_junit(payload)
        self.assertTrue(scan(payload, '.xml'))

    def test_junit_nested_count_discrepancy_and_conflicting_outcomes_block(self):
        for raw in (b'<testsuites><testsuite failures="1"><testcase/></testsuite></testsuites>',
                    b'<testsuite tests="2"><testcase/></testsuite>',
                    b'<testsuite><testcase><failure/><skipped/></testcase></testsuite>'):
            with self.assertRaises(UnsafeEvidence): project_junit(raw)

    def test_projection_cli_safe_outputs_and_fail_closed_without_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); raw = root / 'input.json'; contract = root / 'contract.yaml'
            raw.write_text(json.dumps(raw_report()))
            # JSON is also valid YAML; no new dependency needed by the test itself.
            contract.write_text(json.dumps(SPEC))
            args = [sys.executable, '-B', str(SCRIPTS / 'sanitize-newman-report.py'),
                    str(raw), str(root / 'report.json'), str(root / 'summary.json'), str(contract)]
            result = subprocess.run(args, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0)
            self.assertNotIn(CANARY, result.stdout + result.stderr)
            original = (root / 'report.json').read_bytes()
            again = subprocess.run(args, capture_output=True, text=True)
            self.assertEqual(again.returncode, 1); self.assertEqual((root / 'report.json').read_bytes(), original)
            self.assertNotIn(CANARY.encode(), original)
            broken = root / 'broken.json'; broken.write_text('{' + CANARY)
            failed = subprocess.run([sys.executable, '-B', str(SCRIPTS / 'sanitize-newman-report.py'),
                                    str(broken), str(root / 'absent.json'), str(root / 'absent-summary.json'), str(contract)],
                                    capture_output=True, text=True)
            self.assertEqual(failed.returncode, 1); self.assertFalse((root / 'absent.json').exists())
            self.assertNotIn(CANARY, failed.stdout + failed.stderr)


class ScannerTests(unittest.TestCase):
    def test_structured_fields_headers_and_environments(self):
        for data in ({'email': CANARY}, {'name': CANARY}, {'code': '4321'},
                     {'key': 'Authorization', 'value': CANARY},
                     {'values': [{'key': 'atix_hmac_secret', 'value': CANARY, 'enabled': False}]}):
            with self.subTest(kind=list(data)[0]):
                self.assertTrue(scan(json.dumps(data).encode()))

    def test_nested_json_buffer_base64(self):
        payload = json.dumps({'name': CANARY})
        for data in ({'raw': payload}, {'type': 'Buffer', 'data': list(payload.encode())},
                     {'encoded': base64.b64encode(payload.encode()).decode()}):
            self.assertTrue(scan(json.dumps(data).encode()))

    def test_short_padded_base64_and_unknown_explicit_encoding_block(self):
        for data in ({'value': base64.b64encode(b'4321').decode()}, {'base64': 'not-valid!'}):
            self.assertTrue(scan(json.dumps(data).encode()))

    def test_collection_embedded_script_and_yaml_example_scan(self):
        collection = {'item': [{'event': [{'script': {'exec': ['const payload = ' + json.dumps({'email': 'synthetic@example.invalid'})]}}]}]}
        self.assertTrue(scan(json.dumps(collection).encode()))
        self.assertTrue(scan(b'```yaml\nexample:\n  name: SyntheticCanary\n```', '.md'))

    def test_markdown_pii_and_credentials(self):
        for text in ('fixture.person@example.invalid', '+' + '1' * 10,
                     'Bearer ' + 'X' * 30, 'otp_code: 4321', 'first_name: SyntheticCanary',
                     '-----BEGIN PRIVATE KEY-----', '12|' + 'x' * 40):
            self.assertTrue(scan(text.encode(), '.md'))

    def test_safe_placeholders_and_gate_booleans(self):
        self.assertFalse(scan(json.dumps({'values': [{'key': 'token', 'value': '{{token}}'},
                                                     {'key': 'phone', 'value': ''}],
                                          'hmac_containment_verified': True}).encode()))

    def test_openapi_properties_are_not_runtime_secrets_but_examples_are(self):
        data = {'openapi': '3.1.0', 'paths': {}, 'components': {'schemas': {'Auth': {'properties': {'token': {'type': 'string'}}}}}}
        self.assertFalse(scan(json.dumps(data).encode()))
        data['components']['schemas']['Auth']['example'] = {'token': CANARY}
        self.assertTrue(scan(json.dumps(data).encode()))

    def test_unknown_invalid_and_excessive_nesting_block(self):
        for payload, suffix in [(b'anything', '.bin'), (b'{', '.json'), (b'\xff', '.md'), (b'<unknown/>', '.xml')]:
            self.assertTrue(scan(payload, suffix))
        data = {'x': CANARY}
        for _ in range(MAX_DEPTH + 1): data = {'x': data}
        self.assertIn('ANALYSIS_LIMIT', scan(json.dumps(data).encode()))

    def test_junit_nonprojected_failure_content_blocks(self):
        raw = ('<testsuite><testcase name="' + CANARY + '"><failure message="' + CANARY + '">' + CANARY + '</failure></testcase></testsuite>').encode()
        self.assertTrue(scan(raw, '.xml'))

    def test_zip_secret_nested_zip_unknown_and_traversal_block(self):
        for entries in [ [('report.json', json.dumps({'token': CANARY}))],
                         [('nested.zip', zip_bytes([('inside.json', json.dumps({'name': CANARY}))]))],
                         [('unknown.bin', b'unknown')], [('../escape.json', b'{}')],
                         [('.env', b'private')], [('evidence-raw.json', b'{}')] ]:
            self.assertTrue(scan(zip_bytes(entries), '.zip'))
        self.assertFalse(scan(zip_bytes([('safe.json', b'{}')]), '.zip'))

    def test_scanner_logs_only_categories_even_for_malicious_filename(self):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory) / (CANARY + '.json'); p.write_text(json.dumps({'token': CANARY}))
            output = io.StringIO()
            with contextlib.redirect_stdout(output): code = scan_main([str(p)])
            self.assertEqual(code, 1); self.assertNotIn(CANARY, output.getvalue())
            parsed = json.loads(output.getvalue()); self.assertEqual(parsed['result'], 'FAIL')
            child = subprocess.run([sys.executable, '-B', str(SCRIPTS / 'scan-evidence.py'), str(p)], capture_output=True, text=True)
            self.assertEqual(child.returncode, 1); self.assertNotIn(CANARY, child.stdout + child.stderr)


class BuilderTests(unittest.TestCase):
    def fixture(self, directory):
        source = Path(directory) / 'source'; source.mkdir()
        for name in builder.REQUIRED:
            (source / name).write_text('{}' if name.endswith('.json') else
                                     '<testsuite name="execution-0001"><testcase name="assertion-0001" classname="execution-0001" time="0"/></testsuite>' if name.endswith('.xml') else
                                     'openapi: 3.1.0\npaths: {}\n' if name.endswith('.yaml') else 'Synthetic fixture.\n')
        # Builder now requires real projection structure, not the old empty {} stub.
        import yaml
        (source / 'akubica-openapi-v1.3.0-rc1.1.yaml').write_text(yaml.safe_dump({'openapi': '3.1.0', **SPEC}))
        projected = project_newman(raw_report(), SPEC)
        for phase in ('a', 'b'):
            for kind in ('report', 'summary'):
                (source / f'newman-phase-{phase}-{kind}.json').write_text(json.dumps(projected))
        gate = {'pat_revocation_verified': True, 'hmac_containment_verified': True,
                'manual_pii_review_completed': True, 'staging_build_verified': True}
        (source / 'SECURITY-GATE-v1.3.0-rc1.1.json').write_text(json.dumps(gate))
        return source

    def test_check_only_does_not_build(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.fixture(directory); builder.verify_source(source)
            self.assertEqual(set(p.name for p in Path(directory).iterdir()), {'source'})

    def test_synthetic_zip_postscan_integrity_and_no_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.fixture(directory); destination = Path(directory) / 'akubica-v1.3.0-rc1.1-entrega'
            builder.build(source, destination)
            archive = Path(str(destination) + '.zip')
            self.assertTrue(archive.exists()); self.assertFalse(scan(archive.read_bytes(), '.zip'))
            before = archive.read_bytes()
            with self.assertRaises(UnsafeEvidence): builder.build(source, destination)
            self.assertEqual(archive.read_bytes(), before)

    def test_scan_failure_or_pending_admin_blocks_all_outputs(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.fixture(directory); destination = Path(directory) / 'akubica-v1.3.0-rc1.1-entrega'
            (source / 'newman-phase-a-report.json').write_text(json.dumps({'token': CANARY}))
            with self.assertRaises(UnsafeEvidence): builder.build(source, destination)
            self.assertFalse(destination.exists()); self.assertFalse(Path(str(destination) + '.zip').exists())
            (source / 'newman-phase-a-report.json').write_bytes((source / 'newman-phase-a-summary.json').read_bytes())
            (source / 'SECURITY-GATE-v1.3.0-rc1.1.json').write_text('{}')
            with self.assertRaises(UnsafeEvidence): builder.build(source, destination)
            self.assertFalse(destination.exists())

    def test_existing_rc1_destination_and_extra_files_block(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.fixture(directory)
            with self.assertRaises(UnsafeEvidence): builder.build(source, Path(directory) / 'akubica-v1.3.0-rc1-entrega')
            (source / '.env').write_text(CANARY)
            with self.assertRaises(UnsafeEvidence): builder.verify_source(source)


if __name__ == '__main__':
    unittest.main()

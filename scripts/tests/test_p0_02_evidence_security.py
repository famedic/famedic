"""Synthetic P0-02 regressions; no operational reports, credentials or network."""
import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET

SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))
from evidence_security import Scanner, sensitive_key, scan_main, project_newman, project_junit

CANARY = 'fixture-private-value'
KEYS = ('personal_identifier', 'personal_id', 'curp', 'rfc',
        'delegated_user_id', 'user_id', 'api_key', 'x-api-key')


def scan(obj):
    scanner = Scanner()
    scanner.blob(json.dumps(obj).encode(), '.json')
    return scanner.findings


class ContextualKeysTests(unittest.TestCase):
    def test_direct_fields_require_semantic_detection(self):
        for key in KEYS:
            with self.subTest(key=key):
                self.assertIn('SENSITIVE_FIELD', scan({key: 999991}))

    def test_explicit_separator_and_camelcase_variants(self):
        for key in ('PERSONAL_IDENTIFIER', 'personalIdentifier', 'personal-id', 'personal id',
                    'CURP', 'RFC', 'delegatedUserId', 'Delegated-User-ID', 'UserId',
                    'API_KEY', 'apiKey', 'X-API-KEY', 'x_api_key', 'xApiKey'):
            with self.subTest(key=key):
                self.assertTrue(sensitive_key(key))
                self.assertIn('SENSITIVE_FIELD', scan({key: 999991}))

    def test_nested_arrays_and_objects(self):
        for key in KEYS:
            self.assertIn('SENSITIVE_FIELD', scan({'run': [{'response': {'data': [{key: 999991}]}}]}))

    def test_embedded_json_strings(self):
        for key in KEYS:
            self.assertIn('SENSITIVE_FIELD', scan({'raw': json.dumps({'data': {key: 999991}})}))

    def test_header_maps_and_key_value_pairs(self):
        for key in KEYS:
            self.assertIn('SENSITIVE_FIELD', scan({'headers': {key: CANARY}}))
            self.assertIn('SENSITIVE_PAIR', scan({'headers': [{'key': key, 'value': CANARY}]}))

    def test_name_value_pairs(self):
        for key in KEYS:
            self.assertIn('SENSITIVE_PAIR', scan({'headers': [{'name': key, 'value': CANARY}]}))

    def test_postman_environments_collections_and_apikey_auth(self):
        for key in KEYS:
            self.assertIn('SENSITIVE_PAIR', scan({'values': [{'key': key, 'value': CANARY, 'enabled': False}]}))
            self.assertIn('SENSITIVE_PAIR', scan({'item': [{'request': {'header': [{'key': key, 'value': CANARY}]}}]}))
        self.assertIn('SENSITIVE_FIELD', scan({'auth': {'type': 'apikey', 'apikey': [
            {'key': 'key', 'value': 'X-Api-Key'}, {'key': 'value', 'value': CANARY}, {'key': 'in', 'value': 'header'}]}}))

    def test_synthetic_identifiers_are_not_blanket_blocked(self):
        safe = {'execution_id': 'execution-0001', 'case_id': 'case-0001',
                'assertion_id': 'assertion-0001', 'operation_id': 'case-0002',
                'cart_item_id': 42, 'laboratory_test_id': 43, 'id': 44}
        self.assertFalse(scan(safe))
        for key in safe:
            self.assertFalse(sensitive_key(key))
        for key in ('curp_status', 'rfc_schema', 'api_key_label', 'personal_id_schema'):
            self.assertFalse(sensitive_key(key))

    def test_existing_empty_placeholder_and_boolean_semantics_unchanged(self):
        for key in KEYS:
            for value in (None, '', '[REDACTED]', '{{fixture_value}}', False, True):
                # A marker may still be rejected by existing text/JSON rules.
                expected = scan({'fixture': value})
                self.assertEqual(scan({key: value}), expected)
                self.assertEqual(scan({'key': key, 'value': value}), expected)
            # Numeric values including zero must not be mistaken for booleans.
            for value in (0, 999991):
                self.assertIn('SENSITIVE_FIELD', scan({key: value}))

    def test_existing_secret_categories_remain_blocked(self):
        for key in ('token', 'Authorization', 'otp_code', 'hmac_secret', 'signature',
                    'phone', 'email', 'full_name', 'cookie', 'password'):
            self.assertIn('SENSITIVE_FIELD', scan({key: CANARY}))

    def test_cli_emits_only_fixed_categories_on_all_new_cases(self):
        with tempfile.TemporaryDirectory(prefix='p0-02-canary-') as temp:
            p = Path(temp) / 'fixture.json'
            for key in KEYS:
                p.write_text(json.dumps({key: CANARY}))
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    result = scan_main([str(p)])
                self.assertEqual(result, 1)
                self.assertEqual(set(json.loads(output.getvalue())), {'result', 'categories'})
                self.assertNotIn(CANARY, output.getvalue())
                self.assertNotIn(str(p), output.getvalue())
            child = subprocess.run([sys.executable, '-B', str(SCRIPTS / 'scan-evidence.py'), str(p)], capture_output=True, text=True)
            self.assertEqual(child.returncode, 1)
            self.assertEqual(child.stderr, '')
            self.assertNotIn(CANARY, child.stdout)
            # Broken embedded JSON blocks without returning the parse exception.
            p.write_text(json.dumps({'raw': '{"personal_id":' + CANARY}))
            child = subprocess.run([sys.executable, '-B', str(SCRIPTS / 'scan-evidence.py'), str(p)], capture_output=True, text=True)
            self.assertEqual(child.returncode, 1)
            self.assertEqual(child.stderr, '')
            self.assertNotIn(CANARY, child.stdout)
            self.assertIn('UNANALYZABLE_EMBEDDED_JSON', json.loads(child.stdout)['categories'])

    def test_json_and_junit_projections_preserve_outcomes_and_strip_payloads(self):
        spec = {'paths': {'/cart': {'get': {'responses': {}}}}}
        report = {'run': {'executions': [{
            'request': {'method': 'GET', 'url': 'https://fixture.invalid/api/v1/cart',
                        'header': [{'key': 'X-Api-Key', 'value': CANARY}],
                        'body': {'raw': json.dumps({key: CANARY for key in KEYS})}},
            'response': {'code': 200, 'responseTime': 1, 'stream': json.dumps({key: CANARY for key in KEYS}),
                         'headers': [{'key': 'Authorization', 'value': CANARY}]},
            'assertions': [{'assertion': CANARY}, {'error': {'message': CANARY}}, {'skipped': True}]}]}}
        projected = project_newman(report, spec)
        case = projected['cases'][0]
        self.assertEqual([a['result'] for a in case['assertions']], ['PASS', 'FAIL', 'SKIP'])
        self.assertEqual(set(case), {'case_id', 'method', 'route', 'http_status', 'error_code', 'assertions', 'duration_ms'})
        self.assertNotIn(CANARY, json.dumps(projected))
        self.assertFalse(scan(projected))
        raw = ('<testsuite tests="3" failures="1" errors="0" skipped="1">'
               '<testcase name="fixture" time="0"/><testcase name="fixture" time="0"><failure message="' + CANARY + '">' + CANARY + '</failure></testcase>'
               '<testcase name="fixture" time="0"><skipped/></testcase></testsuite>').encode()
        junit = project_junit(raw)
        self.assertNotIn(CANARY.encode(), junit)
        root = ET.fromstring(junit)
        self.assertEqual([('FAIL' if c.find('failure') is not None else 'SKIP' if c.find('skipped') is not None else 'PASS') for c in root], ['PASS', 'FAIL', 'SKIP'])
        scanner = Scanner(); scanner.blob(junit, '.xml')
        self.assertFalse(scanner.findings)

    def test_no_new_exception_for_hashes_unknown_files_or_excess_depth(self):
        scanner = Scanner(); scanner.blob(b'fixture', '.unknown')
        self.assertIn('UNKNOWN_FORMAT', scanner.findings)
        self.assertTrue(scan({'value': 'a' * 64}))
        nested = {'personal_id': 999991}
        for _ in range(14): nested = {'nested': nested}
        self.assertIn('ANALYSIS_LIMIT', scan(nested))


if __name__ == '__main__':
    unittest.main()

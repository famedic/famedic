"""Exact structural correction and effective response compatibility, offline."""
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
from akubica_contract_validation import load, deref, resolve_object, operations

D = ROOT / 'docs/Akubica/v1.3.0-rc1.1'
P = ROOT / 'docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml'


def at(obj, path):
    for part in path:
        obj = obj[part]
    return obj


def assert_response_references(spec):
    for _, operation in operations(spec).items():
        for response in operation['responses'].values():
            if '$ref' in response:
                assert 'headers' not in response, 'Response Reference Object has sibling headers'
                target = resolve_object(spec, response)
                assert isinstance(target, dict) and 'description' in target


class Phase1C2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads((D / 'FASE-1C.2-CORRECCIONES.json').read_text())
        cls.raw = P.read_bytes()
        cls.after = load(cls.raw)
        before = cls.raw
        for edit in sorted(cls.manifest['corrections'], key=lambda e: e['updated_offset'], reverse=True):
            offset = edit['updated_offset']
            before = before[:offset] + edit['removed_text'].encode() + before[offset:]
        cls.before_raw = before
        cls.before = load(before)

    def test_exact_bytes_before_and_after(self):
        self.assertEqual(hashlib.sha256(self.before_raw).hexdigest(),
                         'a737a0c8380ac470f20d95bc3f08739cf2cd38a71092e1e80c4b1a959905010f')
        self.assertEqual(hashlib.sha256(self.raw).hexdigest(), self.manifest['updated_sha256'])

    def test_exact_inventory_and_no_other_semantic_changes(self):
        inventory = json.loads((D / 'FASE-1C-ERRORES-CONTRACTUALES.json').read_text())['findings']
        edits = self.manifest['corrections']
        self.assertEqual(len(edits), 33)
        self.assertEqual({tuple(e['path']) for e in edits}, {tuple(f['path']) for f in inventory})
        expected = copy.deepcopy(self.before)
        for edit in edits:
            response = at(expected, edit['path'])
            self.assertEqual(set(response), {'$ref', 'headers'})
            self.assertEqual(response['headers'], deref(expected, response['$ref'])['headers'])
            del response['headers']
        self.assertEqual(expected, self.after)
        self.assertEqual(self.before['components'], self.after['components'])

    def test_responses_resolve_and_no_sibling_headers(self):
        assert_response_references(self.after)

    def test_negative_reintroduced_pattern_is_rejected(self):
        broken = copy.deepcopy(self.after)
        edit = self.manifest['corrections'][0]
        at(broken, edit['path'])['headers'] = edit['removed_headers']
        with self.assertRaisesRegex(AssertionError, 'sibling headers'):
            assert_response_references(broken)

    def test_effective_33_responses_are_identical(self):
        for edit in self.manifest['corrections']:
            before = at(self.before, edit['path'])
            effective_before = copy.deepcopy(deref(self.before, before['$ref']))
            effective_before['headers'].update(before['headers'])
            after = at(self.after, edit['path'])
            self.assertEqual(effective_before, deref(self.after, after['$ref']))
            self.assertEqual(before['$ref'], after['$ref'])

    def test_correlation_header_everywhere_previously_defined(self):
        for key, operation in operations(self.before).items():
            for status, response in operation['responses'].items():
                old = deref(self.before, response['$ref']) if '$ref' in response else response
                new_response = operations(self.after)[key]['responses'][status]
                new = deref(self.after, new_response['$ref']) if '$ref' in new_response else new_response
                if 'X-Correlation-ID' in old.get('headers', {}):
                    self.assertEqual(old['headers']['X-Correlation-ID'], new['headers']['X-Correlation-ID'])

    def test_67_operations_and_all_operation_ids_preserved(self):
        old, new = operations(self.before), operations(self.after)
        self.assertEqual(len(new), 67)
        self.assertEqual(set(old), set(new))
        self.assertEqual({k: v['operationId'] for k, v in old.items()},
                         {k: v['operationId'] for k, v in new.items()})

    def test_historical_contracts_and_previous_receipts_unchanged(self):
        for path, expected in self.manifest['historical_and_prior_evidence_sha256'].items():
            self.assertEqual(hashlib.sha256((ROOT / path).read_bytes()).hexdigest(), expected, path)
        historical = self.manifest['historical_git']
        raw = subprocess.check_output(['git', 'show', historical['commit'] + ':' + historical['path']], cwd=ROOT)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), historical['sha256'])


if __name__ == '__main__':
    unittest.main()

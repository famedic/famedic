"""Evidence preparation only. No installs, downloads, HTTP, Laravel or DB."""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))
definition = importlib.util.spec_from_file_location('full_phase1c', SCRIPTS/'validate-openapi31-full.py')
full = importlib.util.module_from_spec(definition)
definition.loader.exec_module(full)


class Phase1CTests(unittest.TestCase):
    def test_blocked_is_bound_to_exact_bytes_and_is_not_validation_pass(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'fixture.yaml';raw=b'openapi: 3.1.0\ninfo: {title: fixture, version: fixture}\npaths: {}\n'
            path.write_bytes(raw)
            with patch.object(full.importlib.util,'find_spec',return_value=None):
                report,code=full.validate_full(path)
            self.assertEqual(code,3);self.assertEqual(report['result'],'BLOCKED')
            self.assertEqual(report['candidate_sha256'],hashlib.sha256(raw).hexdigest())
            self.assertFalse(report['validation_executed']);self.assertFalse(report['downloads_performed'])

    def test_version_approval_is_required_even_if_library_is_present(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'fixture.yaml';path.write_text('openapi: 3.1.0')
            with patch.object(full.importlib.util,'find_spec',return_value=object()), \
                 patch.object(full.importlib.metadata,'version',return_value='0.9.0'), \
                 patch.dict('os.environ',{'AKUBICA_APPROVED_OPENAPI_VALIDATOR_VERSION':''}):
                report,code=full.validate_full(path)
            self.assertEqual(code,3);self.assertEqual(report['reason'],'INSTALLED_VERSION_NOT_APPROVED')
            self.assertFalse(report['validation_executed'])

    def test_cli_cannot_overwrite_historical_blocked_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            target=Path(directory)/'receipt.json';sentinel=b'{"result":"BLOCKED","historical":true}\n';target.write_bytes(sentinel)
            result=subprocess.run([sys.executable,'-B',str(SCRIPTS/'validate-openapi31-full.py'),'--report',str(target)],capture_output=True,text=True)
            self.assertEqual(result.returncode,2);self.assertEqual(target.read_bytes(),sentinel)
            self.assertEqual(json.loads(result.stdout)['reason'],'EXISTING_EVIDENCE_WILL_NOT_BE_OVERWRITTEN')


if __name__=='__main__':unittest.main()

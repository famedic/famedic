"""P0-03 synthetic containment tests. Never read incident reports or run remote tools."""
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

SCRIPTS=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(SCRIPTS),str(SCRIPTS/'tests')]
import evidence_security as security
from evidence_security import Scanner, UnsafeEvidence, historical_reference, validate_projected_newman
import test_evidence_security as original_tests
from test_evidence_security import SPEC, raw_report, zip_bytes
spec=importlib.util.spec_from_file_location('p003_builder',SCRIPTS/'build-akubica-v1.3.0-rc1.1-entrega.py')
builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
CANARY='fixture-canary-only'


def scan(data,suffix='.json'):
    scanner=Scanner();scanner.blob(data,suffix);return scanner.findings


class ContainmentTests(unittest.TestCase):
    def test_exact_names_case_paths_encoding_and_generic_safe_names(self):
        for name in security.HISTORICAL_NAMES:
            for value in (name,name.upper(),'other/'+name,'C:\\delivery\\'+name,name+'.bak',name.replace('-','%2D')):
                self.assertTrue(historical_reference(value))
        for name in ('report.json','summary.json','junit.xml','release.zip','newman-phase-a-report.json'):
            self.assertFalse(historical_reference(name))
        self.assertFalse(scan(b'{}'))

    def test_resource_limits_checked_before_fingerprinting(self):
        scanner=Scanner()
        with patch.object(security.hashlib,'sha256',side_effect=AssertionError('must_not_hash')):
            scanner.blob(b'x'*(security.MAX_BYTES+1),'.json')
        self.assertIn('ANALYSIS_LIMIT',scanner.findings)

    def test_named_historical_file_rejected_before_content_read(self):
        with tempfile.TemporaryDirectory() as temp:
            p=Path(temp)/'NEWMAN-STAGING-REPORT-SANITIZED.JSON';p.write_text('{}')
            scanner=Scanner()
            with patch.object(security,'read_limited',side_effect=AssertionError('must_not_read')):
                scanner.path(p)
            self.assertIn('HISTORICAL_ARTIFACT',scanner.findings)

    def test_renamed_content_fingerprint_and_hardlink_alias(self):
        fixture=b'{"cases":[]}'
        digest=hashlib.sha256(fixture).hexdigest()
        with patch.object(security,'load_redistribution_policy',return_value=frozenset({digest})):
            with tempfile.TemporaryDirectory() as temp:
                original=Path(temp)/'fixture.json';original.write_bytes(fixture)
                alias=Path(temp)/'report.json';os.link(original,alias)
                scanner=Scanner();scanner.path(alias)
                self.assertIn('HISTORICAL_FINGERPRINT',scanner.findings)
            self.assertIn('HISTORICAL_FINGERPRINT',scan(zip_bytes([('other/renamed.json',fixture)]),'.zip'))

    def test_symlink_and_resolved_historical_parent_do_not_bypass(self):
        with tempfile.TemporaryDirectory() as temp:
            original=Path(temp)/'akubica-v1.3.0-rc1-entrega';original.mkdir();(original/'report.json').write_text('{}')
            alias=Path(temp)/'alias';alias.symlink_to(original,target_is_directory=True)
            scanner=Scanner();scanner.path(alias/'report.json')
            self.assertIn('HISTORICAL_ARTIFACT',scanner.findings)

    def test_zip_historical_members_and_nested_archives_blocked(self):
        for name in security.HISTORICAL_NAMES:
            for member in (name,'other/'+name.upper()):
                self.assertIn('HISTORICAL_ARTIFACT',scan(zip_bytes([(member,'{}')]),'.zip'))
        self.assertIn('HISTORICAL_ARTIFACT',scan(zip_bytes([('akubica-v1.3.0-rc1-entrega/','')]),'.zip'))
        nested=zip_bytes([('outer.zip',zip_bytes([('other/newman-staging-summary.json','{}')]))])
        self.assertIn('HISTORICAL_ARTIFACT',scan(nested,'.zip'))

    def test_clean_zip_and_generic_names_allowed(self):
        junit=b'<testsuite name="execution-0001"><testcase name="assertion-0001" classname="execution-0001" time="0"/></testsuite>'
        self.assertFalse(scan(zip_bytes([('reports/report.json','{}'),('reports/summary.json','{}'),('reports/junit.xml',junit)]),'.zip'))

    def test_zip_traversal_drive_aliases_and_case_collisions_blocked(self):
        for name in ('../report.json','%2e%2e/report.json','C:/report.json','other\\report.json'):
            self.assertIn('UNSAFE_ARCHIVE_MEMBER',scan(zip_bytes([(name,'{}')]),'.zip'))
        self.assertIn('UNSAFE_ARCHIVE_MEMBER',scan(zip_bytes([('A/report.json','{}'),('a/REPORT.JSON','{}')]),'.zip'))
        encoded='newman-staging-summary.json'
        for _ in range(10):encoded=encoded.replace('%','%25').replace('-','%2D')
        self.assertTrue(scan(zip_bytes([(encoded,'{}')]),'.zip'))

    def test_manifests_and_hash_inventories_cannot_reference_incident(self):
        name='newman-staging-summary.json'
        for data,suffix in ((('- '+name).encode(),'.md'),(json.dumps({'files':['other/'+name.upper()]}).encode(),'.json'),
                            (b'{"files":["newman\\u002dstaging-summary.json"]}', '.json')):
            self.assertIn('HISTORICAL_REFERENCE',scan(data,suffix))
        scanner=Scanner();scanner.blob(('a'*64+'  '+name+'\n').encode(),'.txt',inventory=True)
        self.assertIn('HISTORICAL_REFERENCE',scanner.findings)
        scanner=Scanner();scanner.blob(('a'*64+'  report.json\n').encode(),'.txt',inventory=True)
        self.assertFalse(scanner.findings)

    def test_policy_missing_invalid_or_empty_fails_closed(self):
        with tempfile.TemporaryDirectory() as temp:
            p=Path(temp)/'policy.json'
            with patch.object(security,'REDISTRIBUTION_POLICY',p):
                with self.assertRaises(UnsafeEvidence):Scanner()
                for data in ({},{'version':1,'purpose':'DENY_HISTORICAL_INCIDENT_REDISTRIBUTION','historical_names':list(security.HISTORICAL_NAMES),'historical_file_sha256':{}}):
                    p.write_text(json.dumps(data))
                    with self.assertRaises(UnsafeEvidence):Scanner()

    def test_simulated_sensitive_json_and_junit_rejected(self):
        self.assertIn('SENSITIVE_FIELD',scan(json.dumps({'token':CANARY}).encode()))
        xml=('<testsuite><testcase name="fixture"><failure message="'+CANARY+'">'+CANARY+'</failure></testcase></testsuite>').encode()
        self.assertIn('JUNIT_FREE_TEXT',scan(xml,'.xml'))

    def test_builder_rejects_empty_or_unprojected_json_without_outputs(self):
        for payload in ({},{'run':{'executions':[]}}, {'execution_id':'execution-0001','cases':[]}):
            with tempfile.TemporaryDirectory() as temp:
                source=original_tests.BuilderTests().fixture(temp);(source/'newman-phase-a-report.json').write_text(json.dumps(payload))
                destination=Path(temp)/'akubica-v1.3.0-rc1.1-entrega'
                with self.assertRaisesRegex(UnsafeEvidence,'INVALID_PROJECTED_REPORT'):builder.build(source,destination)
                self.assertFalse(destination.exists());self.assertFalse(Path(str(destination)+'.zip').exists())

    def test_builder_rejects_historical_extra_and_manifest_reference(self):
        with tempfile.TemporaryDirectory() as temp:
            source=original_tests.BuilderTests().fixture(temp);extra=source/'NEWMAN-STAGING-SUMMARY.JSON';extra.write_text('{}')
            with self.assertRaisesRegex(UnsafeEvidence,'INVENTORY_MISMATCH'):builder.verify_source(source)
            extra.unlink() # synthetic fixture only
            (source/'README-v1.3.0-rc1.1.md').write_text('See newman-staging-summary.json')
            with self.assertRaisesRegex(UnsafeEvidence,'SOURCE_SCAN_FAILED'):builder.verify_source(source)

    def test_builder_rejects_body_header_extra_fields_and_concrete_route(self):
        base=security.project_newman(raw_report(),SPEC)
        for field in ('body','headers','request','response'):
            report=copy.deepcopy(base);report['cases'][0][field]={}
            with self.assertRaisesRegex(UnsafeEvidence,'INVALID_PROJECTED_REPORT'):validate_projected_newman(report,SPEC)
        report=copy.deepcopy(base);report['cases'][0]['route']='/cart/items/999991';report['cases'][0]['method']='DELETE'
        with self.assertRaisesRegex(UnsafeEvidence,'INVALID_PROJECTED_REPORT'):validate_projected_newman(report,SPEC)

    def test_builder_summary_and_junit_counts_must_validate(self):
        with tempfile.TemporaryDirectory() as temp:
            source=original_tests.BuilderTests().fixture(temp);p=source/'newman-phase-a-summary.json';report=json.loads(p.read_text());report['cases'][0]['duration_ms']=9;p.write_text(json.dumps(report))
            with self.assertRaisesRegex(UnsafeEvidence,'PROJECTED_SUMMARY_MISMATCH'):builder.verify_source(source)
            p.write_bytes((source/'newman-phase-a-report.json').read_bytes())
            (source/'newman-phase-a-junit.xml').write_text('<testsuite name="execution-0001" tests="99"><testcase name="assertion-0001" classname="execution-0001" time="0"/></testsuite>')
            with self.assertRaisesRegex(UnsafeEvidence,'JUNIT_COUNT_MISMATCH'):builder.verify_source(source)

    def test_admin_gate_still_blocks_after_valid_report_checks(self):
        with tempfile.TemporaryDirectory() as temp:
            source=original_tests.BuilderTests().fixture(temp);(source/'SECURITY-GATE-v1.3.0-rc1.1.json').write_text('{}')
            with self.assertRaisesRegex(UnsafeEvidence,'ADMIN_GATE_PENDING'):builder.verify_source(source)

    def test_staged_copy_revalidated_before_publish(self):
        with tempfile.TemporaryDirectory() as temp:
            source=original_tests.BuilderTests().fixture(temp);destination=Path(temp)/'akubica-v1.3.0-rc1.1-entrega';real_copy=builder.shutil.copyfile
            def changed_copy(src,dst):
                result=real_copy(src,dst)
                if Path(dst).name=='newman-phase-a-report.json':Path(dst).write_text('{}')
                return result
            with patch.object(builder.shutil,'copyfile',side_effect=changed_copy):
                with self.assertRaisesRegex(UnsafeEvidence,'INVALID_PROJECTED_REPORT'):builder.build(source,destination)
            self.assertFalse(destination.exists());self.assertFalse(Path(str(destination)+'.zip').exists())

    def test_preserve_actual_failures_skips_and_clean_build(self):
        with tempfile.TemporaryDirectory() as temp:
            source=original_tests.BuilderTests().fixture(temp)
            report=security.project_newman(raw_report(),SPEC)
            report['cases'][0]['assertions'].append({'assertion_id':'assertion-0003','result':'SKIP'})
            for phase in ('a','b'):
                for kind in ('report','summary'):(source/f'newman-phase-{phase}-{kind}.json').write_text(json.dumps(report))
            destination=Path(temp)/'akubica-v1.3.0-rc1.1-entrega';builder.build(source,destination)
            after=json.loads((destination/'newman-phase-a-report.json').read_text())
            self.assertEqual(after,report)
            self.assertEqual([a['result'] for a in after['cases'][0]['assertions']],['PASS','FAIL','SKIP'])
            self.assertFalse(scan(Path(str(destination)+'.zip').read_bytes(),'.zip'))

    def test_cli_logs_only_categories_and_fixed_builder_message(self):
        with tempfile.TemporaryDirectory() as temp:
            p=Path(temp)/'NEWMAN-STAGING-SUMMARY.JSON';p.write_text(json.dumps({'token':CANARY}))
            child=subprocess.run([sys.executable,'-B',str(SCRIPTS/'scan-evidence.py'),str(p)],capture_output=True,text=True)
            self.assertEqual(child.returncode,1);self.assertIn('HISTORICAL_ARTIFACT',json.loads(child.stdout)['categories'])
            self.assertNotIn(CANARY,child.stdout+child.stderr);self.assertNotIn(str(p),child.stdout+child.stderr)
            source=original_tests.BuilderTests().fixture(temp);(source/'newman-phase-a-report.json').write_text('{}')
            child=subprocess.run([sys.executable,'-B',str(SCRIPTS/'build-akubica-v1.3.0-rc1.1-entrega.py'),str(source)],capture_output=True,text=True)
            self.assertEqual(child.returncode,1);self.assertEqual(child.stdout.strip(),'FAIL: build blocked; input values and paths omitted')
            self.assertEqual(child.stderr,'');self.assertNotIn(str(source),child.stdout)

    def test_reversible_copy_runbook_only_with_synthetic_files(self):
        with tempfile.TemporaryDirectory() as temp:
            source=Path(temp)/'fixture.json';source.write_text('{"fixture":true}')
            before=hashlib.sha256(source.read_bytes()).hexdigest()
            quarantine=Path(temp)/'incident';quarantine.mkdir(mode=0o700)
            target=quarantine/'artifact.json'
            with target.open('xb') as handle:handle.write(source.read_bytes())
            target.chmod(0o600)
            self.assertEqual(hashlib.sha256(target.read_bytes()).hexdigest(),before)
            self.assertTrue(source.exists());self.assertEqual(target.stat().st_mode&0o777,0o600)
            restored=Path(temp)/'restored.json'
            with restored.open('xb') as handle:handle.write(target.read_bytes())
            self.assertEqual(restored.read_bytes(),source.read_bytes())
            self.assertTrue(target.exists());self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(),before)


if __name__=='__main__':unittest.main()

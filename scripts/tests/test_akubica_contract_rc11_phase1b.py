"""Phase 1B: synthetic-only schema, PHP middleware and serializer validation."""
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from akubica_contract_validation import *
from akubica_nullable_audit import ROWS, node_at


class Phase1BTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.spec = load((ROOT/CANDIDATE_PATH).read_text())
        cls.audit = json.loads((ROOT/'docs/Akubica/v1.3.0-rc1.1/FASE-1B-NULLABLE.json').read_text())['rows']

    def valid(self, schema, value):
        return not errors_for(self.spec, schema, value)

    def sample(self, schema):
        if 'enum' in schema:
            return next(x for x in schema['enum'] if x is not None)
        if schema.get('format') == 'date': return '2026-11-05'
        if schema.get('format') == 'date-time': return '2026-11-05T18:00:00Z'
        if schema.get('format') == 'email': return 'fixture@example.invalid'
        return {'integer':1, 'number':1.5, 'boolean':True, 'string':'fixture',
                'object':{'id':1,'name':'fixture'}}[schema['type']]

    def test_all_26_paths_reviewed_exactly_once_without_blanket_replacement(self):
        self.assertEqual(len(ROWS),26);self.assertEqual(len(self.audit),26)
        self.assertEqual({x['path'] for x in self.audit},{x['path'] for x in ROWS})
        self.assertEqual(sum(x['action']=='ALLOW_NULL' for x in self.audit),22)
        self.assertEqual(sum(x['action']=='REMOVE_ANNOTATION' for x in self.audit),4)
        self.assertFalse(any(n.get('nullable') is True for _,n in walk(self.spec)))

    def test_22_evidenced_nulls_validate_specific_schema_and_old_annotation_fails(self):
        for row in self.audit:
            if row['action']!='ALLOW_NULL':continue
            with self.subTest(field=row['path']):
                schema=node_at(self.spec['components']['schemas'],row['path'])
                self.assertFalse(self.valid(row['before'],None))
                self.assertTrue(self.valid(schema,None))
                self.assertTrue(self.valid(schema,self.sample(row['before'])))

    def test_4_omitted_or_non_null_fields_remain_non_nullable(self):
        for row in self.audit:
            if row['action']!='REMOVE_ANNOTATION':continue
            schema=node_at(self.spec['components']['schemas'],row['path'])
            self.assertFalse(self.valid(schema,None))
            self.assertTrue(self.valid(schema,self.sample(row['before'])))

    def test_nullable_changes_preserve_enum_bounds_formats_and_object_closure(self):
        for row in self.audit:
            before=deepcopy(row['before']);before.pop('nullable')
            after=deepcopy(node_at(self.spec['components']['schemas'],row['path']))
            if row['action']=='ALLOW_NULL':
                self.assertEqual(after.pop('type'),[before.pop('type'),'null'])
            self.assertEqual(after,before)
            schema=node_at(self.spec['components']['schemas'],row['path'])
            bad=[] if row['before']['type']=='object' else {}
            self.assertFalse(self.valid(schema,bad))

    def test_whole_membership_none_and_sponsored_payloads(self):
        fields=['plan_code','plan_name','source','starts_at','expires_at','ineligibility_reason']
        value={'status':'none','purchase_eligible':True,**{k:None for k in fields}}
        schema={'$ref':'#/components/schemas/MembershipStatus'}
        self.assertTrue(self.valid(schema,value))
        value.update(status='active',purchase_eligible=False,source='sponsored',ineligibility_reason='sponsored_membership_active')
        self.assertTrue(self.valid(schema,value))
        value['source']='invented-source';self.assertFalse(self.valid(schema,value))

    def test_whole_pricing_null_payload_and_constraints(self):
        names=['public_price_cents','savings_cents','savings_percentage','tax_included','effective_at','valid_until']
        value={'famedic_price_cents':13700,'currency':'MXN','comparison_status':'public_price_unavailable',**{k:None for k in names}}
        schema={'$ref':'#/components/schemas/LaboratoryPricing'}
        self.assertTrue(self.valid(schema,value))
        for key,bad in [('savings_cents',-1),('savings_percentage',101),('effective_at','invalid-date')]:
            wrong=deepcopy(value);wrong[key]=bad;self.assertFalse(self.valid(schema,wrong))

    def test_nullable_requests_do_not_remove_validation_on_non_null_values(self):
        for path,bad in [('TaxProfileWriteRequest/properties/email','invalid-email'),
                         ('TaxProfileWriteRequest/properties/cfdi_use','x'*11),
                         ('AddressWriteRequest/properties/additional_references','x'*256),
                         ('CancelOrderRequest/properties/reason','x'*501)]:
            schema=node_at(self.spec['components']['schemas'],path)
            self.assertTrue(self.valid(schema,None));self.assertFalse(self.valid(schema,bad))

    def test_actual_serializers_emit_null_or_omit_in_memory_without_db(self):
        result=subprocess.run(['php','scripts/tests/validate_nullable_serializers.php'],cwd=ROOT,capture_output=True,text=True,check=True)
        data=json.loads(result.stdout)
        self.assertTrue(all(data.pop('pricing_nulls').values()));self.assertTrue(all(data.values()))

    def test_e164_positive_canonical_json_inputs_unchanged(self):
        values=['+'+'1'*length for length in range(8,16)]
        result=subprocess.run(['php','scripts/tests/validate_atix_form_request.php'],cwd=ROOT,input=json.dumps(values),capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(result.stdout),[True]*len(values))

    def test_e164_negative_whitespace_and_types_fail_after_middleware(self):
        values=['+'+'1'*10+c for c in ['\n','\r','\r\n','\t',' ','\u00a0','\x00']]+[' '+'+'+'1'*10,chr(92)+'1'*10,None,True,123,[],{}]
        result=subprocess.run(['php','scripts/tests/validate_atix_form_request.php'],cwd=ROOT,input=json.dumps(values),capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(result.stdout),[False]*len(values))
        self.assertEqual(e164_alignment(self.spec)['result'],'PASS')

    def test_security_declarations_and_required_atix_headers(self):
        self.assertEqual(declared_auth_checks(self.spec)['result'],'PASS')
        bad=deepcopy(self.spec);bad['paths']['/cart']['get']['security']=[]
        self.assertEqual(declared_auth_checks(bad)['result'],'FAIL')
        bad=deepcopy(self.spec);bad['paths']['/cart']['get']['security']=[{'unknownScheme':[]}]
        self.assertIn('UNDECLARED_SECURITY_SCHEME',structural_checks(bad))

    def test_request_body_and_auth_scope_malformations_are_not_green(self):
        bad=deepcopy(self.spec);bad['paths']['/cart/items']['post']['requestBody']={}
        self.assertIn('INVALID_REQUEST_BODY',structural_checks(bad))
        bad=deepcopy(self.spec);bad['paths']['/cart']['get']['security']=[{'bearerAuth':['invented-scope']}]
        self.assertIn('SCOPES_ON_NON_OAUTH_SCHEME',structural_checks(bad))

    def test_full_validator_missing_dependency_is_blocked_not_pass(self):
        definition=importlib.util.spec_from_file_location('full_validator',ROOT/'scripts/validate-openapi31-full.py')
        module=importlib.util.module_from_spec(definition);definition.loader.exec_module(module)
        with patch.object(module.importlib.util,'find_spec',return_value=None):
            result,code=module.validate_full(ROOT/CANDIDATE_PATH)
        self.assertEqual(code,3);self.assertEqual(result['result'],'BLOCKED')
        self.assertFalse(result['downloads_performed'])


if __name__=='__main__':unittest.main()

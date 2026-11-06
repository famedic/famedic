"""Contract-only tests. No application bootstrap, DB, exposed report, or HTTP."""
from copy import deepcopy
import json
from pathlib import Path
import re
import subprocess
import tempfile
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from akubica_contract_validation import *
from akubica_contract_fixtures import fixtures


class ContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.spec = load((ROOT / CANDIDATE_PATH).read_text())
        cls.rc1 = load((ROOT / RC1_PATH).read_text())
        cls.historical, cls.baseline = baseline_evidence()

    def valid(self, schema, value):
        return not errors_for(self.spec, schema, value)

    def named(self, name):
        return {'$ref': '#/components/schemas/' + name}

    def test_openapi_structure_references_ids_and_json_schema_meta(self):
        self.assertEqual(structural_checks(self.spec), [])
        self.assertEqual(self.spec['info']['version'], '1.3.0-rc1.1')

    def test_baseline_bytes_and_historical_provenance(self):
        self.assertEqual(len(operations(self.historical)), 61)
        self.assertEqual(len(operations(load((ROOT / BASE_PATH).read_text()))), 67)
        self.assertEqual(self.baseline['historical_contractual_approval'], 'PENDING')

    def test_no_operations_or_identifiers_changed_or_finalized_from_proposal(self):
        old = operations(self.rc1); new = operations(self.spec)
        self.assertEqual(set(old), set(new)); self.assertEqual(len(new), 67)
        self.assertTrue(all(old[k]['operationId'] == new[k]['operationId'] for k in old))
        self.assertFalse(any('/cart/benefits' in p for p, _ in new))

    def test_payment_link_operation_and_generic_legacy_schema_untouched(self):
        self.assertEqual(self.spec['paths']['/checkout/payment-link'],self.rc1['paths']['/checkout/payment-link'])
        self.assertEqual(self.spec['components']['schemas']['ApiSuccessResponse'],self.rc1['components']['schemas']['ApiSuccessResponse'])

    def test_semantic_diff_against_history_finds_six_additions(self):
        diff = semantic_diff(self.historical, self.spec)
        self.assertEqual(len(diff['operations_added']), 6)
        self.assertEqual(diff['operations_removed'], [])
        self.assertEqual(diff['operation_ids_changed'], [])

    def test_yaml_duplicates_and_remote_references_rejected(self):
        with self.assertRaises(ContractError): load('openapi: 3.1.0\nopenapi: 3.1.0')
        bad = deepcopy(self.spec)
        bad['components']['schemas']['Remote'] = {'$ref': 'https://fixture.invalid/schema'}
        self.assertIn('UNRESOLVED_OR_EXTERNAL_REFERENCE', structural_checks(bad))
        with self.assertRaises(Exception): list(validator(bad, {'$ref': 'https://fixture.invalid/schema'}).iter_errors({}))

    def test_e164_parsed_yaml_boundary_matrix_and_laravel_pcre(self):
        phone_schema = self.spec['components']['schemas']['AtixPhoneLookupRequest']['properties']['phone']
        pattern = phone_schema['pattern']
        self.assertEqual(pattern.count(chr(92)), 1)
        cases = [('+'+'1'*8, True), ('+'+'1'*15, True), ('+'+'1'*7, False),
                 ('+'+'1'*16, False), ('+'+'0'+'1'*9, False), ('1'*10, False),
                 ('+'+'1'*8+' ', False), ('+'+'1'*4+' '+'1'*4, False), ('+'+'x'*10, False),
                 (chr(92)+'1'*10, False), ('+'+'1'*8+'\n', False)]
        # JSON Schema regex uses search semantics; use its validator, not only fullmatch.
        for text, expected in cases:
            self.assertEqual(self.valid(phone_schema, text), expected)
        source = (ROOT / 'app/Http/Requests/Api/V1/Integrations/AtixPhoneLookupRequest.php').read_text()
        php_pattern = re.search(r"'regex:([^']+)'", source).group(1)
        code = '$x=json_decode(stream_get_contents(STDIN),true);echo json_encode(array_map(fn($v)=>preg_match($x["pattern"],$v)===1,$x["values"]));'
        result = subprocess.run(['php','-r',code], input=json.dumps({'pattern':php_pattern,'values':[x[0] for x in cases]}), capture_output=True, text=True, check=True)
        # Fase 1B: /D rejects terminal LF, and the isolated FormRequest harness
        # separately checks that global trimming cannot normalize it away.
        results=json.loads(result.stdout)
        self.assertEqual(results, [x[1] for x in cases])

    def test_all_synthetic_responses_validate_schema_and_actual_operation(self):
        for path, method, status, name, payload in fixtures():
            with self.subTest(schema=name, method=method, path=path):
                self.assertTrue(self.valid(self.named(name), payload))
                self.assertTrue(self.valid(response_schema(self.spec,path,method,status), payload))

    def test_reproduces_rc1_defects_and_corrected_specific_schemas(self):
        cases=[('ContextualOtpVerifiedTokenResponse',fixtures()[1][-1]['data']),
               ('MedicalServiceCartTotalsResponse',fixtures()[7][-1]),
               ('MedicalServiceCheckoutPrepareResponse',fixtures()[11][-1]),
               ('MedicalServiceBenefitResponse',fixtures()[14][-1])]
        for name,payload in cases:
            self.assertTrue(errors_for(self.rc1,self.named(name),payload))
        legacy_medical=deepcopy(fixtures()[5][-1])
        for item in legacy_medical['data']['items']:
            item['unit_price_cents']=30000;item['subtotal_cents']=30000
        self.assertTrue(errors_for(self.rc1,response_schema(self.rc1,'/cart','get','200'),legacy_medical))

    def test_mixed_service_shapes_and_invalid_token_types_are_rejected(self):
        hybrid=deepcopy(fixtures()[5][-1]);hybrid['data']['brand']='olab'
        self.assertFalse(self.valid(response_schema(self.spec,'/cart','get','200'),hybrid))
        token=deepcopy(fixtures()[1][-1]['data'])
        for key,value in [('expires_at','invalid-date'),('expires_in','10800'),('token_type','Other'),('token',17)]:
            bad=deepcopy(token);bad[key]=value
            self.assertFalse(self.valid(self.named('ContextualOtpVerifiedTokenResponse'),bad))

    def test_oneof_accepts_exactly_one_variant(self):
        for path, method, status, _, payload in fixtures():
            schema = response_schema(self.spec,path,method,status)
            union = schema.get('oneOf')
            if union:
                self.assertEqual(sum(self.valid(branch,payload) for branch in union), 1)
        login = self.spec['components']['schemas']['LoginVerifiedResponse']['properties']['data']['oneOf']
        for *_, payload in fixtures()[:2]:
            self.assertEqual(sum(self.valid(branch,payload['data']) for branch in login),1)

    def test_unexpected_properties_rejected_at_envelope_and_data(self):
        for path, method, status, _, payload in fixtures():
            schema = response_schema(self.spec,path,method,status)
            for at_data in [False,True]:
                bad=deepcopy(payload); target=bad['data'] if at_data else bad; target['unexpected_fixture']=True
                self.assertFalse(self.valid(schema,bad))

    def test_missing_required_fields_and_invalid_types_rejected(self):
        for path,method,status,_,payload in fixtures():
            schema=response_schema(self.spec,path,method,status)
            for bad in [{'success':True}, {'success':False,'data':payload['data']}, {'success':True,'data':[]}]:
                self.assertFalse(self.valid(schema,bad))

    def test_contextual_and_noncontextual_login_disjoint_and_strict(self):
        plain, contextual=[deepcopy(c[-1]['data']) for c in fixtures()[:2]]
        schema=self.named('ContextualOtpVerifiedTokenResponse')
        self.assertTrue(self.valid(schema,contextual)); self.assertFalse(self.valid(schema,plain))
        for key,value in [('otp_verified',False),('assurance_level','recognized_unverified'),('unexpected_fixture',True)]:
            bad=deepcopy(contextual);bad[key]=value;self.assertFalse(self.valid(schema,bad))
        bad=deepcopy(contextual);bad['user']['unexpected_fixture']=True;self.assertFalse(self.valid(schema,bad))
        for allowed,value,expected in [(False,{'allowed':False},True),(True,{'allowed':True,'first_name':None},True),
                                       (True,{'allowed':True},False),(False,{'allowed':False,'first_name':'fixture'},False)]:
            bad=deepcopy(contextual);bad['personalization']=value;self.assertEqual(self.valid(schema,bad),expected)

    def test_legacy_bearer_fields_remain_unchanged(self):
        self.assertEqual(self.spec['components']['schemas']['TokenResponse'],self.rc1['components']['schemas']['TokenResponse'])
        plain=fixtures()[0][-1]['data'];self.assertTrue(self.valid(self.named('LoginTokenData'),plain))
        plain.pop('user');self.assertTrue(self.valid(self.named('LoginTokenData'),plain))
        plain['unexpected_fixture']=True;self.assertFalse(self.valid(self.named('LoginTokenData'),plain))

    def test_request_code_oneof_handles_context_without_overlap(self):
        schema=self.spec['paths']['/auth/login/request-code']['post']['requestBody']['content']['application/json']['schema']
        plain={'phone':'+'+'1'*10};context={**plain,'conversation_id':'fixture-conversation','channel_event_id':'fixture-event','channel':'whatsapp','audience':'leo'}
        for value in [plain,context,{'email':'fixture@example.invalid'}]:
            self.assertEqual(sum(self.valid(branch,value) for branch in schema['oneOf']),1)
        incomplete={**plain,'conversation_id':'fixture-conversation'}
        self.assertFalse(self.valid(schema,incomplete))

    def test_medical_totals_current_empty_benefits_is_explicit(self):
        value=deepcopy(fixtures()[7][-1]);schema=self.named('MedicalServiceCartTotalsResponse')
        self.assertTrue(self.valid(schema,value));value['data']['applied_benefits']=[{}]
        self.assertFalse(self.valid(schema,value))

    def test_credit_without_public_code_and_configured_membership_price(self):
        cart=deepcopy(fixtures()[5][-1]);cart['data']['applied_benefits']=[{'id':1,'type':'credit','coupon_id':2,'code':None,'description':'fixture','applied_cents':1000,'currency':'MXN'}]
        self.assertTrue(self.valid(self.named('MedicalServiceCartResponse'),cart))
        cart['data']['applied_benefits'][0]['unexpected_fixture']=True
        self.assertFalse(self.valid(self.named('MedicalServiceCartResponse'),cart))

    def test_platform_flags_preserve_actual_distinct_semantics(self):
        value=deepcopy(fixtures()[11][-1]);schema=self.named('MedicalServiceCheckoutPrepareResponse')
        value['data']['requirements']['has_items']=False;value['data']['cart']['items']=[]
        self.assertTrue(self.valid(schema,value))  # navigation remains true with empty cart
        for key in ['assistant_can_pay','can_continue_to_payment_platform']:
            bad=deepcopy(value);bad['data'][key]=True;self.assertFalse(self.valid(schema,bad))
        bad=deepcopy(value);bad['data'].pop('can_continue_to_payment_platform');self.assertFalse(self.valid(schema,bad))

    def test_laboratory_coupon_remove_requires_totals_only_after_effect(self):
        false_case=deepcopy(fixtures()[18][-1]);true_case=deepcopy(fixtures()[19][-1]);schema=self.named('LaboratoryCouponRemovedResponse')
        self.assertTrue(self.valid(schema,false_case));self.assertTrue(self.valid(schema,true_case))
        true_case['data'].pop('totals');self.assertFalse(self.valid(schema,true_case))
        false_case['data']['totals']={};self.assertFalse(self.valid(schema,false_case))

    def test_nested_lab_checkout_resources_are_typed(self):
        value=deepcopy(fixtures()[10][-1]);schema=self.named('LaboratoryCheckoutPrepareResponse')
        value['data']['contacts']=[{'id':1,'full_name':'fixture','name':None,'paternal_lastname':None,'maternal_lastname':None,
             'phone':None,'phone_country':None,'birth_date':None,'gender':None}]
        value['data']['addresses']=[{'id':1,'street':None,'external_number':None,'internal_number':None,'neighborhood':None,
              'city':None,'state':None,'postal_code':None,'country':'MX'}]
        value['data']['payment_methods']=[{'id':1,'brand':'fixture','last4':None,'expiration_month':None,'expiration_year':None,'holder_name':None,'type':'card'}]
        self.assertTrue(self.valid(schema,value))
        value['data']['contacts'][0]['unexpected_fixture']=True;self.assertFalse(self.valid(schema,value))

    def test_examples_and_mutated_operation_id_are_checked(self):
        self.assertTrue(all(case['valid'] for case in example_results(self.spec)))
        bad=deepcopy(self.spec);ops=list(operations(bad).values());ops[1]['operationId']=ops[0]['operationId']
        self.assertIn('DUPLICATE_OPERATION_ID',structural_checks(bad))

    def test_postman_entire_mirror_and_method_are_checked(self):
        collection={'item':[{'request':{'method':'GET','url':'{{base_url}}/cart'}}]}
        self.assertEqual(postman_checks(self.spec,collection,deepcopy(collection))['failures'],[])
        mirror=deepcopy(collection);mirror['item'][0]['request']['method']='PATCH'
        self.assertIn('POSTMAN_MIRROR_CONTENT_MISMATCH',postman_checks(self.spec,collection,mirror)['failures'])
        self.assertIn('POSTMAN_METHOD_PATH_NOT_CONTRACTED',postman_checks(self.spec,mirror,mirror)['failures'])

    def test_semantic_diff_tracks_unchanged_operations_with_changed_components(self):
        bad=deepcopy(self.spec)
        bad['components']['schemas']['LaboratoryCart']['properties']['total_cents']={'type':'string'}
        delta=semantic_diff(self.spec,bad)
        self.assertEqual(delta['operations_modified'],[])
        self.assertIn('GET /cart',delta['operations_affected_via_components'])

    def test_cli_rejects_invalid_example_without_printing_payload(self):
        bad=deepcopy(self.spec)
        bad['components']['schemas']['LoginVerifiedResponse']['examples']=[{'success':'synthetic-negative-canary','data':{}}]
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'candidate.yaml'
            path.write_text(yaml.safe_dump(bad,sort_keys=False))
            result=subprocess.run([sys.executable,'-B',str(ROOT/'scripts/validate-akubica-rc1-artifacts.py'),'--contract',str(path)],capture_output=True,text=True)
            self.assertEqual(result.returncode,1)
            self.assertNotIn('synthetic-negative-canary',result.stdout+result.stderr)
            self.assertEqual(json.loads(result.stdout)['result'],'FAIL')


if __name__=='__main__':unittest.main()

#!/usr/bin/env python3
"""Validate observed synthetic HTTP responses against frozen RC1.1; preview only a separate proposed delta."""
import argparse,copy,hashlib,json
from pathlib import Path
import yaml
from jsonschema import Draft202012Validator,FormatChecker
ROOT=Path(__file__).resolve().parents[1]
EXPECTED='523a566bab22fffb4c0ad8fbb29d79cc4823e99769494b1fd135f6639094f9a4'
POINTER='/paths/~1auth~1login~1request-code/post/responses/202/content/application~1json/schema/allOf/1/properties/data/oneOf/0'
GUARDED={'allOf':[{'$ref':'#/components/schemas/OtpChallenge'},{'not':{'required':['personalization']}}]}

def response_schema(contract,observation):
    node=contract['paths'][observation['path']][observation['method'].lower()]['responses'][str(observation['http'])]
    while '$ref' in node:
        target=contract
        for part in node['$ref'].split('/')[1:]:target=target[part.replace('~1','/').replace('~0','~')]
        node=target
    return node.get('content',{}).get('application/json',{}).get('schema')
def validate(contract,o):
    schema=response_schema(contract,o)
    if not schema:return [{'reason':'NO_CONCRETE_SCHEMA'}]
    root=dict(schema,components=contract['components'])
    return [{'instance_path':list(e.path),'schema_path':list(e.schema_path),'validator':e.validator,'message':e.message} for e in Draft202012Validator(root,format_checker=FormatChecker()).iter_errors(o['response'])]
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--input',type=Path,required=True);ap.add_argument('--report',type=Path,required=True);ap.add_argument('--delta',type=Path,required=True);args=ap.parse_args()
    if args.report.exists() or args.delta.exists():raise SystemExit('Refusing to overwrite evidence')
    contract_file=ROOT/'docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml';digest=hashlib.sha256(contract_file.read_bytes()).hexdigest()
    if digest!=EXPECTED:raise SystemExit('Frozen contract hash mismatch')
    c=yaml.safe_load(contract_file.read_text());data=json.loads(args.input.read_text());observations=data['functional_observations'];draft=copy.deepcopy(c)
    branches=draft['paths']['/auth/login/request-code']['post']['responses']['202']['content']['application/json']['schema']['allOf'][1]['properties']['data']['oneOf']
    assert branches[0]=={'$ref':'#/components/schemas/OtpChallenge'};branches[0]=copy.deepcopy(GUARDED)
    rows=[]
    for o in observations:
        errors=validate(c,o);preview=validate(draft,o)
        rows.append({'id':o['id'],'method':o['method'],'path':o['path'],'http':o['http'],'provenance':o['provenance'],
            'redacted_fields':o['redacted_fields'],'frozen_result':'FAIL' if errors else 'PASS','frozen_errors':errors,
            'proposed_delta_preview_result':'FAIL' if preview else 'PASS','preview_errors':preview})
    context=next(o for o in observations if o['id']=='RF02-login-start')
    base=copy.deepcopy(context);base['response']['data'].pop('personalization')
    malformed=copy.deepcopy(context);malformed['response']['data']['personalization']={'allowed':'not-a-boolean'}
    safety={'noncontext_challenge_still_accepted':not validate(draft,base),
        'malformed_personalization_rejected':bool(validate(draft,malformed)),
        'frozen_contract_also_accepts_malformed_personalization':not validate(c,malformed)}
    args.delta.write_text(json.dumps({'status':'PROPOSED_REQUIRES_CONTRACT_APPROVAL','base_sha256':EXPECTED,'applied_to_candidate':False,
        'reason':'Operation-local disjoint oneOf: OtpChallenge permits additional properties and overlaps its contextual extension. Keep global schemas unchanged.',
        'json_patch':[{'op':'test','path':POINTER,'value':{'$ref':'#/components/schemas/OtpChallenge'}},
            {'op':'replace','path':POINTER,'value':GUARDED}]},ensure_ascii=False,indent=2)+'\n')
    out={'phase':'2C-1','scope':'FROZEN_CONTRACT_VS_OBSERVED_SYNTHETIC_RESPONSES','candidate_sha256':digest,
        'candidate_modified':False,'rows':rows,
        'frozen_counts':{s:sum(x['frozen_result']==s for x in rows) for s in ['PASS','FAIL']},
        'delta_preview_counts':{s:sum(x['proposed_delta_preview_result']==s for x in rows) for s in ['PASS','FAIL']},
        'preview_safety_checks':safety,'contract_approval':'PENDING','full_openapi_validation_of_delta':'NOT_RUN_NOT_A_CANDIDATE',
        'result':'FAIL_FROZEN_CONTRACT' if any(x['frozen_result']=='FAIL' for x in rows) else 'PASS'}
    out['candidate_modified']=hashlib.sha256(contract_file.read_bytes()).hexdigest()!=EXPECTED
    args.report.write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'result':out['result'],'frozen':out['frozen_counts'],'preview_only':out['delta_preview_counts'],'safety':safety}))
    return out['result']!='PASS'
if __name__=='__main__':raise SystemExit(main())

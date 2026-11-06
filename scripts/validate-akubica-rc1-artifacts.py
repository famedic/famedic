#!/usr/bin/env python3
"""Offline RC1.1 validation. Does not certify release or administrative closure."""
import argparse
import hashlib
import json
from pathlib import Path
import sys
from akubica_contract_validation import *

def evaluate(path):
    spec=load(path.read_text())
    historical, provenance=baseline_evidence()
    rc1=load((ROOT/RC1_PATH).read_text())
    current=load((ROOT/BASE_PATH).read_text())
    structure=structural_checks(spec)
    alignment=e164_alignment(spec)
    auth=declared_auth_checks(spec)
    examples=example_results(spec)
    collection=json.loads((ROOT/'postman/Famedic-Akubica-v1.3.0-rc1.postman_collection.json').read_text())
    mirror=json.loads((ROOT/'docs/Akubica/v1.3.0-rc1/Famedic-Akubica-v1.3.0-rc1.postman_collection.json').read_text())
    postman=postman_checks(spec,collection,mirror)
    sys.path.insert(0,str(ROOT/'scripts/tests'))
    from akubica_contract_fixtures import fixtures
    sample_results=[]
    for index,(route,method,status,name,payload) in enumerate(fixtures(),1):
        schema=response_schema(spec,route,method,status)
        valid=not errors_for(spec,schema,payload)
        if 'oneOf' in schema:
            valid=valid and sum(not errors_for(spec,x,payload) for x in schema['oneOf'])==1
        sample_results.append({'case_id':f'case-{index:04d}','result':'PASS' if valid else 'FAIL'})
    delta=semantic_diff(rc1,spec)
    checks={'security_declarations_vs_routes':auth['result']=='PASS',
            'e164_contract_formrequest':alignment['result']=='PASS',
            'nullable_reviewed_annotations_resolved':not any(node.get('nullable') is True for _,node in walk(spec['components']['schemas'])),
            'openapi_31_parse':spec.get('openapi')=='3.1.0',
            'candidate_version':spec.get('info',{}).get('version')=='1.3.0-rc1.1',
            'structure_local_refs_and_schema_meta':not structure,
            'historical_bytes_preserved':True,
            'operation_set_and_ids_preserved':not delta['operations_added'] and not delta['operations_removed'] and not delta['operation_ids_changed'],
            'embedded_examples':all(x['valid'] for x in examples),
            'synthetic_response_fixtures':all(x['result']=='PASS' for x in sample_results),
            'postman_method_paths_and_full_mirror':not postman['failures']}
    return {'result':'PASS_LOCAL_SCOPE' if all(checks.values()) else 'FAIL',
            'release_ready':False,'phase_0_administrative_status':'OPEN',
            'validator_scope':'offline structural OpenAPI checks plus JSON Schema 2020-12; official full OpenAPI metaschema validator unavailable',
            'runtime_e164_alignment':alignment,
            'declared_authentication':auth,
            'inherited_nullable_annotations_outside_corrected_schemas':['/'.join(loc) for loc,node in walk(spec['components']['schemas']) if node.get('nullable') is True],
            'datetime_format_scope':'explicit timezone and seconds representation emitted by runtime; not all RFC3339 leap-second forms',
            'provenance':provenance,'candidate_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
            'operations':{'historical':len(operations(historical)),'current_base':len(operations(current)),
                          'rc1':len(operations(rc1)),'rc11':len(operations(spec))},
            'checks':checks,'structural_failures':structure,
            'examples':{'total':len(examples),'failed':sum(not x['valid'] for x in examples)},
            'fixtures':sample_results,'postman':postman,
            'historical_to_rc1':semantic_diff(historical,rc1),
            'historical_to_rc11':semantic_diff(historical,spec),
            'rc1_to_rc11':delta,
            'current_base_to_rc1':semantic_diff(current,rc1),
            'current_base_to_rc11':semantic_diff(current,spec)}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--contract',type=Path,default=ROOT/CANDIDATE_PATH)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    try:
        report=evaluate(args.contract)
        if args.report:
            args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+chr(10))
        # No payloads, examples, parser exception messages or credentials in logs.
        print(json.dumps({'result':report['result'],'checks':report['checks'],
                          'examples':report['examples'],'operations':report['operations'],
                          'release_ready':False,'runtime_e164_alignment':report['runtime_e164_alignment']['result']}))
        return int(report['result']=='FAIL')
    except Exception:
        print('{"result":"FAIL","categories":["CONTRACT_VALIDATION_BLOCKED"]}')
        return 1

if __name__=='__main__':
    raise SystemExit(main())

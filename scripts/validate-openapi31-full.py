#!/usr/bin/env python3
"""Use an independently approved installed validator. Never install/download."""
import argparse
import importlib.util
import importlib.metadata
import json
import hashlib
from pathlib import Path
from akubica_contract_validation import ROOT, CANDIDATE_PATH, load, walk


def validate_full(path):
    raw = Path(path).read_bytes()
    identity = {'candidate_sha256': hashlib.sha256(raw).hexdigest(),
                'validation_executed': False, 'downloads_performed': False}
    if importlib.util.find_spec('openapi_spec_validator') is None:
        return {**identity, 'result': 'BLOCKED', 'reason': 'APPROVED_FULL_VALIDATOR_NOT_INSTALLED',
                'tool_candidate': 'openapi-spec-validator', 'recommended_version': '0.9.0'}, 3
    # Installation alone is not organizational approval. An operator must
    # explicitly confirm the installed version before using this future path.
    import os
    version = importlib.metadata.version('openapi-spec-validator')
    if os.environ.get('AKUBICA_APPROVED_OPENAPI_VALIDATOR_VERSION') != version:
        return {**identity, 'result': 'BLOCKED', 'reason': 'INSTALLED_VERSION_NOT_APPROVED',
                'installed_version': version}, 3
    spec = load(raw)
    if any(not str(node['$ref']).startswith('#/') for _, node in walk(spec) if '$ref' in node):
        return {**identity, 'result': 'FAIL', 'reason': 'EXTERNAL_REFERENCE_BLOCKED'}, 1
    from openapi_spec_validator import OpenAPIV31SpecValidator
    count = sum(1 for _ in OpenAPIV31SpecValidator(spec).iter_errors())
    return {**identity, 'validation_executed': True,
            'result': 'FAIL' if count else 'PASS', 'errors': count, 'installed_version': version,
            'scope': 'integral OpenAPI 3.1; runtime/examples/security behavior require separate checks',
            'downloads_performed': False}, int(count > 0)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--contract', type=Path, default=ROOT / CANDIDATE_PATH)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    if args.report and (args.report.exists() or args.report.is_symlink()):
        print('{"result":"FAIL","reason":"EXISTING_EVIDENCE_WILL_NOT_BE_OVERWRITTEN"}')
        return 2
    try:
        report, code = validate_full(args.contract)
    except Exception:
        report, code = {'result': 'FAIL', 'reason': 'FULL_VALIDATION_BLOCKED_VALUES_OMITTED'}, 1
    if args.report:
        try:
            with args.report.open('x') as handle:
                handle.write(json.dumps(report, indent=2) + '\n')
        except Exception:
            print('{"result":"FAIL","reason":"REPORT_WRITE_BLOCKED"}')
            return 2
    print(json.dumps(report))
    return code


if __name__ == '__main__':
    raise SystemExit(main())

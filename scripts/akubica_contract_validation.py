"""Offline OpenAPI structural checks and JSON Schema 2020-12 validation.

No Laravel bootstrap, report with credentials, DB, network or external ref retrieval.
This is not a replacement for a full official OpenAPI metaschema validator.
"""
from __future__ import annotations
from copy import deepcopy
import hashlib
import json
from pathlib import Path
import re
import subprocess
from datetime import datetime
import yaml
from jsonschema import Draft202012Validator, FormatChecker
from referencing import Registry

ROOT = Path(__file__).resolve().parents[1]
HISTORICAL_COMMIT = '6ffb35c7cdaee26e8deb09398c85ff9bcf7c46e5'
BASE_PATH = 'docs/Akubica/akubica-openapi.yaml'
RC1_PATH = 'docs/Akubica/akubica-openapi-v1.3.0-rc1.yaml'
CANDIDATE_PATH = 'docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml'
HISTORICAL_HASH = '6ace97823aa528171f0d5c34c8ec9674d5730c429db2a95c18dc0c560f5b3749'
CURRENT_BASE_HASH = 'c46ec1f832f2c8a22bdc9cefc2341ab1afc63e01f94dc6cd3946a5ae34e4e2d2'
RC1_HASH = 'bbf110ffb55f9092b7a9678561509e11bfb0eb644251efc2b44ceec3a8d1398b'
METHODS = {'get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'}
FORMATS = FormatChecker()


@FORMATS.checks('date-time', raises=(ValueError, TypeError))
def explicit_datetime(value):
    # The installed jsonschema lacks the optional RFC3339 checker. Do not let
    # format silently become an annotation. Runtime emits ISO timestamps with
    # seconds and a timezone; validate that concrete supported representation.
    if not isinstance(value, str):
        return True
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[Zz]|[+-]\d{2}:\d{2})', value):
        return False
    parsed = datetime.fromisoformat(value.replace('t','T').replace('z','Z').replace('Z','+00:00'))
    return parsed.tzinfo is not None


class ContractError(ValueError):
    pass


class UniqueLoader(yaml.SafeLoader):
    pass


def unique_mapping(loader, node, deep=False):
    result = {}
    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        if key in result:
            raise ContractError('DUPLICATE_YAML_KEY')
        result[key] = loader.construct_object(value_node, deep=deep)
    return result


UniqueLoader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, unique_mapping)


def load(text):
    result = yaml.load(text, Loader=UniqueLoader)
    if not isinstance(result, dict):
        raise ContractError('INVALID_DOCUMENT')
    return result


def deref(spec, reference):
    if not isinstance(reference, str) or not reference.startswith('#/'):
        raise ContractError('EXTERNAL_REFERENCE_BLOCKED')
    value = spec
    for part in reference[2:].split('/'):
        value = value[part.replace('~1', '/').replace('~0', '~')]
    return value


def walk(value, location=()):
    if isinstance(value, dict):
        yield location, value
        for key, child in value.items():
            yield from walk(child, location + (str(key),))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from walk(child, location + (str(index),))


def resolve_object(spec, value):
    seen = set()
    while isinstance(value, dict) and '$ref' in value:
        reference = value['$ref']
        if reference in seen:
            raise ContractError('REFERENCE_CYCLE')
        seen.add(reference)
        value = deref(spec, reference)
    return value


def operations(spec):
    return {(path, method): value for path, item in spec['paths'].items()
            for method, value in item.items() if method in METHODS}


def no_retrieval(_):
    raise ContractError('EXTERNAL_REFERENCE_BLOCKED')


def validator(spec, schema):
    # Fragment references resolve inside this document; the empty registry
    # deliberately refuses HTTP/file ref retrieval.
    root = {'components': spec['components'], **deepcopy(schema)}
    return Draft202012Validator(root, format_checker=FORMATS, registry=Registry(retrieve=no_retrieval))


def errors_for(spec, schema, instance):
    return list(validator(spec, schema).iter_errors(instance))


def response_schema(spec, path, method, status):
    response = spec['paths'][path][method]['responses'][str(status)]
    response = resolve_object(spec, response)
    return response['content']['application/json']['schema']


def e164_alignment(spec):
    # Synthetic values only, sent over stdin. No application/DB/network.
    phone = spec['components']['schemas']['AtixPhoneLookupRequest']['properties']['phone']
    positive = ['+' + '1' * n for n in range(8, 16)]
    negative = ['+'+'1'*7, '+'+'1'*16, '+'+'0'+'1'*9, '1'*10,
                *['+'+'1'*10+c for c in [' ', '\t', '\n', '\r', '\r\n', '\u00a0', '\x00']],
                ' '+'+'+'1'*10, '+'+'1'*4+' '+'1'*5, chr(92)+'1'*10, '+'+'x'*10,
                None, True, 1234567890, [], {}]
    values = positive + negative
    expected = [True]*len(positive) + [False]*len(negative)
    contract = [not errors_for(spec, phone, value) for value in values]
    process = subprocess.run(['php', str(ROOT/'scripts/tests/validate_atix_form_request.php')],
        input=json.dumps(values), text=True, capture_output=True, cwd=ROOT)
    if process.returncode:
        return {'result': 'BLOCKED', 'reason': 'ISOLATED_FORMREQUEST_FAILED'}
    actual = json.loads(process.stdout)
    return {'result': 'PASS' if actual == expected == contract else 'FAIL',
            'positive_cases': len(positive), 'negative_cases': len(negative),
            'scope': 'JSON body through TrimStrings/ConvertEmptyStringsToNull and actual FormRequest; no app bootstrap'}


def declared_auth_checks(spec):
    failures = []
    for (path, method), operation in operations(spec).items():
        actual = operation.get('security', spec.get('security', []))
        public = ((path.startswith('/auth/') and method == 'post')
                  or (path.startswith('/catalog/') and method == 'get')
                  or (path == '/secure-downloads/{token}' and method == 'get')
                  or (path == '/memberships/plans' and method == 'get'))
        expected = [] if public else [{'atixHmac': []}] if path == '/integrations/atix/phone-lookups' else [{'bearerAuth': []}]
        if actual != expected:
            failures.append(method.upper() + ' ' + path)
    atix = operations(spec)[('/integrations/atix/phone-lookups', 'post')]
    parameters = [resolve_object(spec, p) for p in atix.get('parameters', [])]
    mandatory = {'X-Correlation-ID','X-Integration-Key-Id','X-Timestamp','X-Nonce','X-Signature','Idempotency-Key'}
    if not mandatory.issubset({p.get('name') for p in parameters if p.get('in') == 'header' and p.get('required') is True}):
        failures.append('ATIX_REQUIRED_HEADERS')
    return {'result': 'PASS' if not failures else 'FAIL', 'failures': failures,
            'operations_checked': len(operations(spec)),
            'scope': 'declarations vs local route groups; no proof of deployed enforcement or Leo payment restriction'}


def structural_checks(spec):
    failures = []
    if spec.get('openapi') != '3.1.0' or not isinstance(spec.get('info', {}).get('title'), str):
        failures.append('OPENAPI_ROOT')
    if not isinstance(spec.get('paths'), dict):
        return failures + ['OPENAPI_PATHS']
    ids = []
    for (path, method), operation in operations(spec).items():
        if not path.startswith('/') or not isinstance(operation, dict):
            failures.append('INVALID_OPERATION'); continue
        identifier = operation.get('operationId')
        if not isinstance(identifier, str) or not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]*', identifier):
            failures.append('INVALID_OPERATION_ID')
        ids.append(identifier)
        responses = operation.get('responses')
        if not isinstance(responses, dict) or not responses:
            failures.append('MISSING_RESPONSES'); continue
        for status, response in responses.items():
            if not isinstance(status, str) or not re.fullmatch(r'default|[1-5](?:[0-9]{2}|XX)', status):
                failures.append('INVALID_STATUS')
            response = resolve_object(spec, response)
            if not isinstance(response.get('description'), str):
                failures.append('MISSING_RESPONSE_DESCRIPTION')
        parameters = spec['paths'][path].get('parameters', []) + operation.get('parameters', [])
        resolved = [resolve_object(spec, p) for p in parameters]
        names = {(p.get('in'), p.get('name')) for p in resolved}
        if len(names) != len(resolved):
            failures.append('DUPLICATE_PARAMETER')
        for parameter in re.findall(r'\{([^}]+)\}', path):
            if not any(p.get('in') == 'path' and p.get('name') == parameter and p.get('required') is True for p in resolved):
                failures.append('MISSING_PATH_PARAMETER')
    if len(ids) != len(set(ids)):
        failures.append('DUPLICATE_OPERATION_ID')
    schemes = spec.get('components', {}).get('securitySchemes', {})
    for security in [spec.get('security', [])] + [op.get('security', spec.get('security', [])) for op in operations(spec).values()]:
        if not isinstance(security, list):
            failures.append('INVALID_SECURITY_REQUIREMENT'); continue
        for alternative in security:
            if not isinstance(alternative, dict):
                failures.append('INVALID_SECURITY_REQUIREMENT'); continue
            for name, scopes in alternative.items():
                if name not in schemes or not isinstance(scopes, list):
                    failures.append('UNDECLARED_SECURITY_SCHEME')
                elif schemes[name].get('type') in ('apiKey', 'http') and scopes:
                    failures.append('SCOPES_ON_NON_OAUTH_SCHEME')
    for _, op in operations(spec).items():
        if 'requestBody' in op:
            body = resolve_object(spec, op['requestBody'])
            if not isinstance(body.get('content'), dict) or not body['content']:
                failures.append('INVALID_REQUEST_BODY')
    for _, node in walk(spec):
        if '$ref' in node:
            try:
                deref(spec, node['$ref'])
            except Exception:
                failures.append('UNRESOLVED_OR_EXTERNAL_REFERENCE')
    for _, schema in spec.get('components', {}).get('schemas', {}).items():
        try:
            Draft202012Validator.check_schema(schema)
        except Exception:
            failures.append('INVALID_JSON_SCHEMA')
    for _, node in walk(spec):
        if 'schema' in node and isinstance(node['schema'], (dict, bool)):
            try:
                Draft202012Validator.check_schema(node['schema'])
            except Exception:
                failures.append('INVALID_INLINE_JSON_SCHEMA')
    return sorted(set(failures))


def example_results(spec):
    results = []
    for location, node in walk(spec):
        cases = []
        # Media/parameter examples alongside a schema.
        if isinstance(node.get('schema'), dict):
            if 'example' in node:
                cases.append((node['schema'], node['example']))
            for value in (node.get('examples') or {}).values() if isinstance(node.get('examples'), dict) else []:
                if isinstance(value, dict) and 'value' in value:
                    cases.append((node['schema'], value['value']))
        # Schema examples (including nested schemas), with their own constraints.
        if 'schemas' in location and 'example' in node:
            cases.append((node, node['example']))
        if 'schemas' in location and isinstance(node.get('examples'), list):
            cases.extend((node, value) for value in node['examples'])
        for index, (schema, value) in enumerate(cases):
            results.append({'location': '/'.join(location), 'index': index,
                            'valid': not bool(errors_for(spec, schema, value))})
    return results


def semantic_diff(before, after):
    old, new = operations(before), operations(after)
    bs = before['components']['schemas']; cs = after['components']['schemas']
    label = lambda key: key[1].upper() + ' ' + key[0]
    def expanded(value, spec, stack=()):
        if isinstance(value, dict):
            result = {k: expanded(v, spec, stack) for k, v in value.items() if k != '$ref'}
            if '$ref' in value:
                ref = value['$ref']
                result['$ref'] = ref
                if ref not in stack:
                    try:
                        result['x-resolved-comparison'] = expanded(deref(spec, ref), spec, stack + (ref,))
                    except KeyError:
                        result['x-unresolved-historical-reference'] = True
            return result
        if isinstance(value, list):
            return [expanded(v, spec, stack) for v in value]
        return value
    return {'operations_added': sorted(label(k) for k in new.keys() - old.keys()),
            'operations_removed': sorted(label(k) for k in old.keys() - new.keys()),
            'operations_modified': sorted(label(k) for k in old.keys() & new.keys() if old[k] != new[k]),
            'operation_ids_changed': sorted(label(k) for k in old.keys() & new.keys() if old[k]['operationId'] != new[k]['operationId']),
            'operations_affected_via_components': sorted(label(k) for k in old.keys() & new.keys()
                if old[k] == new[k] and expanded(old[k], before) != expanded(new[k], after)),
            'schemas_added': sorted(cs.keys() - bs.keys()), 'schemas_removed': sorted(bs.keys() - cs.keys()),
            'schemas_modified': sorted(k for k in bs.keys() & cs.keys() if bs[k] != cs[k])}


def baseline_evidence():
    raw = subprocess.check_output(['git', 'show', HISTORICAL_COMMIT + ':' + BASE_PATH], cwd=ROOT, stderr=subprocess.DEVNULL)
    current = (ROOT / BASE_PATH).read_bytes(); rc1 = (ROOT / RC1_PATH).read_bytes()
    sha = lambda data: hashlib.sha256(data).hexdigest()
    if (sha(raw), sha(current), sha(rc1)) != (HISTORICAL_HASH, CURRENT_BASE_HASH, RC1_HASH):
        raise ContractError('HISTORICAL_HASH_MISMATCH')
    historical = load(raw)
    unresolved = set()
    for _, node in walk(historical):
        if '$ref' in node:
            try:
                deref(historical, node['$ref'])
            except KeyError:
                unresolved.add(node['$ref'])
    return historical, {'historical_commit': HISTORICAL_COMMIT, 'historical_sha256': sha(raw),
                       'current_base_sha256': sha(current), 'rc1_sha256': sha(rc1),
                       'historical_contractual_approval': 'PENDING',
                       'historical_unresolved_references': sorted(unresolved)}


def postman_checks(spec, collection, mirror):
    failures = []
    if collection != mirror:
        failures.append('POSTMAN_MIRROR_CONTENT_MISMATCH')
    count = 0; covered = set()
    def visit(items):
        nonlocal count
        for item in items:
            if 'item' in item:
                visit(item['item']); continue
            if 'request' not in item:
                continue
            count += 1; request = item['request']; url = request.get('url')
            if isinstance(url, dict): url = url.get('raw')
            if not isinstance(url, str):
                failures.append('POSTMAN_URL_UNKNOWN'); continue
            route = url.replace('{{base_url}}', '').split('?')[0]
            matches = []
            for (p, m) in operations(spec):
                pattern = re.sub(r'\\\{[^}]+\\\}', r'[^/]+', re.escape(p))
                normalized = re.sub(r'\{\{[^}]+\}\}', 'fixture', route)
                if m.upper() == request.get('method') and re.fullmatch(pattern, normalized): matches.append((p, m))
            if not matches: failures.append('POSTMAN_METHOD_PATH_NOT_CONTRACTED')
            else: covered.update(matches)
    visit(collection.get('item', []))
    return {'requests': count, 'covered_operations': len(covered), 'failures': sorted(set(failures))}

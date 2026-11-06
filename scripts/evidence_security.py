#!/usr/bin/env python3
"""Offline evidence boundary. Never log input values or exception messages."""
from __future__ import annotations

import base64
import hashlib
import unicodedata
from urllib.parse import unquote
import binascii
import io
import json
import math
import re
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path, PurePosixPath

MAX_BYTES = 8 * 1024 * 1024
MAX_TOTAL = 32 * 1024 * 1024
MAX_DEPTH = 12
MAX_NODES = 100000
METHODS = {'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'}
SECRET_KEY = re.compile(r'token|authorization|cookie|secret|password|hmac|signature|otp|phone|email|first.?name|last.?name|full.?name|^name$|^code$|integration.?key', re.I)
# Exact contextual vocabulary; never treat every *_id as personal data.
CONTEXTUAL_SECRET_KEYS = frozenset({
    'personalidentifier', 'personalid', 'curp', 'rfc',
    'delegateduserid', 'userid', 'apikey', 'xapikey',
})
PLACEHOLDER = re.compile(r'^\{\{[A-Za-z_][A-Za-z0-9_]*\}\}$')
PATTERNS = {
    'BEARER': re.compile(r'\bBearer\s+(?!\{\{)[A-Za-z0-9._~+|/=-]{8,}', re.I),
    'SANCTUM': re.compile(r'\b[0-9]+\|[A-Za-z0-9]{16,}'),
    'JWT': re.compile(r'\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+'),
    'EMAIL': re.compile(r'\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b'),
    'PHONE': re.compile(r'(?<!\w)\+[1-9][0-9 ()-]{7,20}[0-9](?!\w)'),
    'PRIVATE_KEY': re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
    'ASSIGNMENT': re.compile(r'''(?ix)(?:["']?(?:access_token|authorization|hmac_secret|atix_hmac_secret|otp_code|password|phone|email|first_name|full_name|name)["']?\s*[:=]\s*["']?)(?!\s*(?:\{\{|null\b|false\b|true\b|\[REDACTED\]|["']\s*[,}\n]))[A-Za-z0-9+][^\s,}"']{2,}'''),
}


def sensitive_key(value):
    # Same rule for direct fields and Postman/header name-value pairs.
    normalized = re.sub(r'[-_\s]', '', value).lower()
    return bool(SECRET_KEY.search(value) or normalized in CONTEXTUAL_SECRET_KEYS)


class UnsafeEvidence(ValueError):
    """Only fixed categories may cross the CLI boundary."""


def strict_json(text):
    def pairs(items):
        obj = {}
        for k, v in items:
            if k in obj:
                raise UnsafeEvidence('DUPLICATE_JSON_KEY')
            obj[k] = v
        return obj
    return json.loads(text, object_pairs_hook=pairs,
                      parse_constant=lambda _: (_ for _ in ()).throw(UnsafeEvidence('NONFINITE_JSON')))


def read_limited(path):
    p = Path(path)
    if p.is_symlink() or not p.is_file() or p.stat().st_size > MAX_BYTES:
        raise UnsafeEvidence('UNREADABLE_OR_OVERSIZE')
    return p.read_bytes()


def json_body(response):
    if not isinstance(response, dict):
        raise UnsafeEvidence('INVALID_RESPONSE')
    raw = response.get('stream', response.get('body'))
    if raw in (None, ''):
        return {}
    if isinstance(raw, dict) and raw.get('type') == 'Buffer':
        data = raw.get('data')
        if not isinstance(data, list) or len(data) > MAX_BYTES or any(type(n) is not int or not 0 <= n <= 255 for n in data):
            raise UnsafeEvidence('INVALID_BUFFER')
        raw = bytes(data).decode('utf-8')
    if isinstance(raw, str):
        if len(raw.encode('utf-8')) > MAX_BYTES:
            raise UnsafeEvidence('OVERSIZE_BODY')
        raw = strict_json(raw)
    if not isinstance(raw, dict):
        raise UnsafeEvidence('UNANALYZABLE_BODY')
    return raw


def safe_number(value, minimum=0, maximum=10**9):
    if type(value) not in (int, float) or not math.isfinite(value) or not minimum <= value <= maximum:
        raise UnsafeEvidence('INVALID_NUMBER')
    return value


def contract_context(spec):
    """Derive only routes and declared uppercase error labels, never examples."""
    methods = {}
    errors = set()
    for route, item in spec.get('paths', {}).items():
        if not isinstance(route, str) or not re.fullmatch(r'/[A-Za-z0-9_/{}/.-]*', route):
            raise UnsafeEvidence('INVALID_CONTRACT_ROUTE')
        for method, operation in item.items():
            if method.upper() not in METHODS:
                continue
            methods[(method.upper(), route)] = re.compile('^' + re.sub(r'\\\{[^}]+\\\}', r'[^/]+', re.escape(route)) + '$')
            for response in operation.get('responses', {}).values():
                errors.update(re.findall(r'\b[A-Z][A-Z0-9_]{2,63}\b', response.get('description', '')))
    if not methods:
        raise UnsafeEvidence('EMPTY_CONTRACT')
    return methods, errors


def parameterized_route(request, routes):
    from urllib.parse import urlsplit
    method = request.get('method')
    if method not in METHODS:
        raise UnsafeEvidence('INVALID_METHOD')
    url = request.get('url')
    if isinstance(url, dict):
        url = url.get('raw')
    if not isinstance(url, str):
        raise UnsafeEvidence('INVALID_URL')
    url = url.replace('{{base_url}}', '')
    path = urlsplit(url).path
    if path.startswith('/api/v1/'):
        path = path[len('/api/v1'):]
    matches = [route for (m, route), pattern in routes.items() if m == method and pattern.fullmatch(path)]
    # Static routes win over parameter routes (e.g. /cart/totals).
    exact = [p for p in matches if '{' not in p]
    matches = exact or matches
    if len(matches) != 1:
        raise UnsafeEvidence('UNMAPPED_ROUTE')
    return method, matches[0]


def project_newman(raw, spec):
    routes, errors = contract_context(spec)
    executions = raw.get('run', {}).get('executions') if isinstance(raw, dict) else None
    if not isinstance(executions, list) or not executions or len(executions) > 10000:
        raise UnsafeEvidence('INVALID_NEWMAN_REPORT')
    result = []
    for index, entry in enumerate(executions, 1):
        if not isinstance(entry, dict):
            raise UnsafeEvidence('INVALID_EXECUTION')
        method, route = parameterized_route(entry.get('request', {}), routes)
        response = entry.get('response')
        if not isinstance(response, dict) or type(response.get('code')) is not int:
            raise UnsafeEvidence('MISSING_HTTP_RESPONSE')
        status = safe_number(response['code'], 100, 599)
        body = json_body(response)
        error = body.get('error')
        code = error.get('code') if isinstance(error, dict) else None
        if status >= 400 and (not isinstance(code, str) or code not in errors):
            raise UnsafeEvidence('UNDECLARED_ERROR_CODE')
        if status < 400 and code is not None:
            raise UnsafeEvidence('INCONSISTENT_ERROR_CODE')
        assertions = entry.get('assertions')
        if not isinstance(assertions, list) or not assertions:
            raise UnsafeEvidence('MISSING_ASSERTIONS')
        outcomes = []
        for a_index, assertion in enumerate(assertions, 1):
            if not isinstance(assertion, dict):
                raise UnsafeEvidence('INVALID_ASSERTION')
            outcome = 'SKIP' if assertion.get('skipped') else 'FAIL' if assertion.get('error') else 'PASS'
            outcomes.append({'assertion_id': f'assertion-{a_index:04d}', 'result': outcome})
        result.append({'case_id': f'case-{index:04d}', 'method': method, 'route': route,
                       'http_status': status, 'error_code': code,
                       'assertions': outcomes, 'duration_ms': safe_number(response.get('responseTime'))})
    # Reporter-wide failures can include pre-request/test script errors not attached
    # to any assertion. Block instead of silently exporting a false green run.
    failures = raw.get('run', {}).get('failures', [])
    if not isinstance(failures, list):
        raise UnsafeEvidence('INVALID_FAILURES')
    if failures and not any(a['result'] == 'FAIL' for c in result for a in c['assertions']):
        raise UnsafeEvidence('UNMAPPED_RUN_FAILURE')
    return {'execution_id': 'execution-0001', 'cases': result}


def project_junit(raw):
    text = raw.decode('utf-8')
    if '\x00' in text or '<!DOCTYPE' in text.upper() or '<!ENTITY' in text.upper():
        raise UnsafeEvidence('XML_DECLARATION_FORBIDDEN')
    root = ET.fromstring(text)
    if root.tag not in ('testsuite', 'testsuites'):
        raise UnsafeEvidence('UNKNOWN_XML_ROOT')
    allowed = {'testsuite', 'testsuites', 'testcase', 'failure', 'error', 'skipped', 'system-out', 'system-err', 'properties', 'property'}
    if any(e.tag not in allowed for e in root.iter()):
        raise UnsafeEvidence('UNKNOWN_XML_ELEMENT')
    cases = list(root.iter('testcase'))
    if not cases or len(cases) > 10000:
        raise UnsafeEvidence('INVALID_JUNIT_CASES')
    out = ET.Element('testsuite', name='execution-0001', tests=str(len(cases)))
    failures = errors = skipped = 0
    for index, case in enumerate(cases, 1):
        if sum(case.find(tag) is not None for tag in ('failure', 'error', 'skipped')) > 1:
            raise UnsafeEvidence('CONFLICTING_JUNIT_OUTCOMES')
        duration = safe_number(float(case.get('time', '0')))
        item = ET.SubElement(out, 'testcase', name=f'assertion-{index:04d}', classname='execution-0001', time=str(duration))
        for tag in ('failure', 'error', 'skipped'):
            if case.find(tag) is not None:
                ET.SubElement(item, tag)
                failures += tag == 'failure'
                errors += tag == 'error'
                skipped += tag == 'skipped'
    # Suite-level errors have no assertion mapping and must not disappear.
    if any(e.find('error') is not None or e.find('failure') is not None for e in root.iter('testsuite')):
        raise UnsafeEvidence('UNMAPPED_JUNIT_FAILURE')
    out.set('failures', str(failures)); out.set('errors', str(errors)); out.set('skipped', str(skipped))
    for suite in root.iter():
        if suite.tag not in ('testsuite', 'testsuites'):
            continue
        children = list(suite.iter('testcase'))
        counts = {'tests': len(children), 'failures': sum(c.find('failure') is not None for c in children),
                  'errors': sum(c.find('error') is not None for c in children),
                  'skipped': sum(c.find('skipped') is not None for c in children)}
        for key, count in counts.items():
            if key in suite.attrib and int(suite.attrib[key]) != count:
                raise UnsafeEvidence('JUNIT_COUNT_MISMATCH')
    return ET.tostring(out, encoding='utf-8', xml_declaration=True) + b'\n'


HISTORICAL_NAMES = frozenset({
    'newman-staging-report-sanitized.json', 'newman-staging-junit-sanitized.xml',
    'newman-staging-summary.json', 'akubica-v1.3.0-rc1-entrega.zip',
    'akubica-v1.3.0-rc1-entrega',
})
REDISTRIBUTION_POLICY = Path(__file__).with_name('evidence-redistribution-policy.json')


def normalized_reference(value):
    value = unicodedata.normalize('NFKC', value)
    for _ in range(8):
        decoded = unquote(value)
        decoded = re.sub(r'\\u([0-9a-fA-F]{4})', lambda m: chr(int(m[1], 16)), decoded)
        if decoded == value:
            break
        value = unicodedata.normalize('NFKC', decoded)
    if unquote(value) != value or re.search(r'\\u[0-9a-fA-F]{4}', value):
        raise UnsafeEvidence('REFERENCE_ENCODING_LIMIT')
    return value.replace('\\', '/').casefold()


def historical_reference(value):
    value = normalized_reference(value)
    # Include backup/alternate extensions and Windows trailing-dot aliases.
    return any(re.search(r'(?<![a-z0-9_.-])' + re.escape(name) + r'(?=$|[^a-z0-9_-])', value)
               for name in HISTORICAL_NAMES)


def load_redistribution_policy():
    policy = strict_json(read_limited(REDISTRIBUTION_POLICY).decode('utf-8'))
    if (policy.get('version') != 1 or policy.get('purpose') != 'DENY_HISTORICAL_INCIDENT_REDISTRIBUTION'
            or set(policy.get('historical_names', [])) != HISTORICAL_NAMES):
        raise UnsafeEvidence('INVALID_REDISTRIBUTION_POLICY')
    digests = policy.get('historical_file_sha256', {})
    expected = {name for name in HISTORICAL_NAMES if name.startswith('newman-')}
    if set(digests) != expected or not all(isinstance(v, str) and re.fullmatch(r'[a-f0-9]{64}', v) for v in digests.values()):
        raise UnsafeEvidence('INVALID_REDISTRIBUTION_POLICY')
    return frozenset(digests.values())


def validate_projected_newman(report, spec):
    """Accept only the existing projector's schema, never arbitrary JSON."""
    def require(condition):
        if not condition:
            raise UnsafeEvidence('INVALID_PROJECTED_REPORT')
    def identifier(value, prefix):
        return isinstance(value, str) and re.fullmatch(prefix + r'-[0-9]{4,}', value)
    routes, errors = contract_context(spec)
    require(isinstance(report, dict) and set(report) == {'execution_id', 'cases'})
    require(identifier(report['execution_id'], 'execution'))
    cases = report['cases']
    require(isinstance(cases, list) and 0 < len(cases) <= 10000)
    seen = set()
    for case in cases:
        require(isinstance(case, dict) and set(case) == {
            'case_id', 'method', 'route', 'http_status', 'error_code', 'assertions', 'duration_ms'})
        require(identifier(case['case_id'], 'case') and case['case_id'] not in seen)
        seen.add(case['case_id'])
        require(isinstance(case['method'], str) and isinstance(case['route'], str)
                and (case['method'], case['route']) in routes)
        require(type(case['http_status']) is int)
        safe_number(case['http_status'], 100, 599); safe_number(case['duration_ms'])
        code = case['error_code']
        require((isinstance(code, str) and code in errors) if case['http_status'] >= 400 else code is None)
        assertions = case['assertions']
        require(isinstance(assertions, list) and 0 < len(assertions) <= 10000)
        assertion_ids = set()
        for assertion in assertions:
            require(isinstance(assertion, dict) and set(assertion) == {'assertion_id', 'result'})
            require(identifier(assertion['assertion_id'], 'assertion') and assertion['assertion_id'] not in assertion_ids)
            assertion_ids.add(assertion['assertion_id'])
            require(assertion['result'] in ('PASS', 'FAIL', 'SKIP'))


class Scanner:
    """Bounded recursive scanner. Findings never include file names or values."""
    def __init__(self):
        self.historical_digests = load_redistribution_policy()
        self.findings = set()
        self.nodes = 0
        self.total = 0

    def flag(self, category):
        self.findings.add(category)

    def text(self, value, depth=0):
        if historical_reference(value):
            self.flag('HISTORICAL_REFERENCE')
        self.nodes += 1
        if depth > MAX_DEPTH or self.nodes > MAX_NODES:
            self.flag('ANALYSIS_LIMIT'); return
        for category, pattern in PATTERNS.items():
            if pattern.search(value):
                self.flag(category)
        stripped = value.strip()
        if PLACEHOLDER.fullmatch(stripped):
            return
        if stripped.startswith(('{', '[')):
            try:
                self.obj(strict_json(stripped), depth + 1)
            except (ValueError, RecursionError):
                self.flag('UNANALYZABLE_EMBEDDED_JSON')
        # Encoded payloads are never silently trusted. Decode where possible and
        # block even if contents have no known signature (e.g. an encoded name).
        for token in re.findall(r'(?<![\w-])[A-Za-z0-9+/_-]{4,}={0,2}(?![\w-])', value):
            if re.fullmatch(r'[A-Z][A-Z0-9_]{2,63}', token):
                continue  # fixed enum labels, not encoded payloads
            if re.fullmatch(r'[a-fA-F0-9]{32,128}', token) and len(token) != 40:
                self.flag('OPAQUE_HEX_VALUE'); continue
            if re.fullmatch(r'[a-fA-F0-9]{40}', token):
                continue  # Git revision; sensitive key context is still checked
            try:
                decoded = base64.b64decode(token + '=' * (-len(token) % 4), altchars=b'-_', validate=True)
            except (binascii.Error, ValueError):
                continue
            printable = sum(32 <= c < 127 or c in (9, 10, 13) for c in decoded) / max(1, len(decoded))
            if printable < 0.85 and '=' not in token and not (len(token) >= 40 and re.search(r'[0-9]', token) and re.search(r'[a-z]', token) and re.search(r'[A-Z]', token)):
                continue
            self.flag('ENCODED_OR_OPAQUE_VALUE')
            try:
                text = decoded.decode('utf-8')
                if text != value:
                    self.text(text, depth + 1)
            except UnicodeDecodeError:
                self.flag('UNANALYZABLE_ENCODED_VALUE')

    def obj(self, obj, depth=0):
        self.nodes += 1
        if depth > MAX_DEPTH or self.nodes > MAX_NODES:
            self.flag('ANALYSIS_LIMIT'); return
        if isinstance(obj, dict):
            if 'openapi' in obj and 'paths' in obj:
                self.contract(obj, depth + 1)
                return
            if obj.get('type') == 'Buffer':
                self.flag('BUFFER_REPRESENTATION')
                try:
                    self.text(bytes(obj['data']).decode('utf-8'), depth + 1)
                except (ValueError, TypeError, KeyError, UnicodeError):
                    self.flag('UNANALYZABLE_BUFFER')
            for encoded_key in ('base64', 'b64', 'encoded'):
                if isinstance(obj.get(encoded_key), str) and obj[encoded_key]:
                    self.flag('ENCODED_REPRESENTATION')
                    try:
                        self.text(base64.b64decode(obj[encoded_key], validate=True).decode('utf-8'), depth + 1)
                    except (binascii.Error, UnicodeError, ValueError):
                        self.flag('UNANALYZABLE_ENCODED_VALUE')
            # Postman variable/header pairs must be interpreted semantically.
            for pair_key in ('key', 'name'):
                pair = obj.get(pair_key)
                if isinstance(pair, str) and sensitive_key(pair) and self.populated(obj.get('value')):
                    self.flag('SENSITIVE_PAIR')
            for key, value in obj.items():
                if sensitive_key(str(key)) and self.populated(value):
                    self.flag('SENSITIVE_FIELD')
                if key == 'auth' and isinstance(value, dict) and value.get('type') not in (None, 'noauth'):
                    # References are checked recursively; secrets still fail.
                    pass
                if isinstance(value, str):
                    self.text(value, depth + 1)
                elif isinstance(value, (dict, list)):
                    self.obj(value, depth + 1)
        elif isinstance(obj, list):
            for value in obj:
                self.obj(value, depth + 1)
        elif isinstance(obj, str):
            self.text(obj, depth + 1)

    def contract(self, node, depth):
        # Property/schema names are definitions, not populated runtime fields.
        # Examples/defaults remain untrusted payloads; never exempt them.
        self.nodes += 1
        if depth > MAX_DEPTH or self.nodes > MAX_NODES:
            self.flag('ANALYSIS_LIMIT'); return
        if isinstance(node, dict):
            for key, value in node.items():
                if key in ('example', 'default'):
                    self.obj(value, depth + 1)
                elif key == 'examples' and isinstance(value, dict):
                    for example in value.values():
                        if isinstance(example, dict) and 'value' in example:
                            self.obj(example['value'], depth + 1)
                        else:
                            self.obj(example, depth + 1)
                elif key == 'externalValue':
                    self.flag('EXTERNAL_EXAMPLE')
                else:
                    self.contract(value, depth + 1)
        elif isinstance(node, list):
            for value in node:
                self.contract(value, depth + 1)
        elif isinstance(node, str):
            self.text(node, depth + 1)

    @staticmethod
    def populated(value):
        return type(value) is not bool and value not in (None, '', '[REDACTED]') and not (isinstance(value, str) and PLACEHOLDER.fullmatch(value))

    def blob(self, data, suffix, depth=0, inventory=False):
        self.total += len(data)
        if len(data) > MAX_BYTES or self.total > MAX_TOTAL or depth > MAX_DEPTH:
            self.flag('ANALYSIS_LIMIT'); return
        if hashlib.sha256(data).hexdigest() in self.historical_digests:
            self.flag('HISTORICAL_FINGERPRINT'); return
        if suffix == '.zip':
            try:
                with zipfile.ZipFile(io.BytesIO(data)) as archive:
                    seen = set()
                    for info in archive.infolist():
                        name = PurePosixPath(info.filename)
                        if historical_reference(info.filename):
                            self.flag('HISTORICAL_ARTIFACT'); continue
                        if info.is_dir():
                            continue
                        if name.is_absolute() or '..' in name.parts or '\\' in info.filename or info.filename in seen:
                            self.flag('UNSAFE_ARCHIVE_MEMBER'); continue
                        canonical = normalized_reference(info.filename)
                        if canonical in seen or '..' in PurePosixPath(canonical).parts or re.match(r'^[a-z]:', canonical):
                            self.flag('UNSAFE_ARCHIVE_MEMBER'); continue
                        seen.add(canonical)
                        if len(seen) > 1000 or info.file_size > MAX_BYTES or info.flag_bits & 1 or (info.external_attr >> 16) & 0o170000 == 0o120000:
                            self.flag('UNANALYZABLE_ARCHIVE_MEMBER'); continue
                        if self.total + info.file_size > MAX_TOTAL:
                            self.flag('ANALYSIS_LIMIT'); break
                        if forbidden_file(info.filename):
                            self.flag('FORBIDDEN_FILE')
                        self.blob(archive.read(info), name.suffix.lower(), depth + 1, name.name == 'SHA256SUMS')
            except (OSError, ValueError, RuntimeError, zipfile.BadZipFile, NotImplementedError):
                self.flag('UNANALYZABLE_ZIP')
            return
        try:
            text = data.decode('utf-8')
            if '\x00' in text:
                raise UnsafeEvidence('UNKNOWN_TEXT_ENCODING')
            if historical_reference(text):
                self.flag('HISTORICAL_REFERENCE')
            if inventory:
                if not all(re.fullmatch(r'[a-f0-9]{64}  [A-Za-z0-9_./-]+', l) for l in text.splitlines()):
                    self.flag('INVALID_HASH_INVENTORY')
                return
            if suffix == '.json':
                self.obj(strict_json(text), depth + 1)
            elif suffix == '.xml':
                if '<!DOCTYPE' in text.upper() or '<!ENTITY' in text.upper():
                    raise UnsafeEvidence('XML_DECLARATION_FORBIDDEN')
                root = ET.fromstring(text)
                if root.tag not in ('testsuite', 'testsuites'):
                    raise UnsafeEvidence('UNKNOWN_XML_ROOT')
                # Only our strict projected JUnit is distributable.
                for element in root.iter():
                    if element.tag not in ('testsuite', 'testsuites', 'testcase', 'failure', 'error', 'skipped'):
                        self.flag('UNSAFE_JUNIT_ELEMENT')
                    if (element.text and element.text.strip()) or (element.tail and element.tail.strip()):
                        self.flag('JUNIT_FREE_TEXT')
                    allowed = {'name', 'tests', 'failures', 'errors', 'skipped'} if element.tag in ('testsuite', 'testsuites') else {'name', 'classname', 'time'} if element.tag == 'testcase' else set()
                    if set(element.attrib) - allowed:
                        self.flag('JUNIT_EXTRA_ATTRIBUTE')
                    for key, value in element.attrib.items():
                        if key in ('name', 'classname') and not re.fullmatch(r'(?:execution|assertion)-[0-9]{4,}', value):
                            self.flag('JUNIT_NON_SYNTHETIC_ID')
                        self.text(value, depth + 1)
            elif suffix in ('.yaml', '.yml'):
                import yaml
                self.obj(yaml.safe_load(text), depth + 1)
            elif suffix in ('.md', '.txt'):
                self.text(text, depth + 1)
                # Also inspect fenced JSON/YAML; do not treat prose as a schema.
                for kind, body in re.findall(r'```(json|yaml|yml)\s*\n(.*?)```', text, re.S):
                    if kind == 'json':
                        self.obj(strict_json(body), depth + 1)
                    else:
                        import yaml
                        self.obj(yaml.safe_load(body), depth + 1)
            else:
                self.flag('UNKNOWN_FORMAT')
        except Exception:
            # Parser exceptions may quote secrets. Never propagate their text.
            self.flag('UNANALYZABLE_CONTENT')

    def path(self, path):
        path = Path(path)
        if historical_reference(str(path)) or historical_reference(str(path.resolve())):
            self.flag('HISTORICAL_ARTIFACT'); return
        if path.is_symlink():
            self.flag('SYMLINK'); return
        if path.is_dir():
            for index, child in enumerate(sorted(path.iterdir()), 1):
                if index > 1000:
                    self.flag('ANALYSIS_LIMIT'); break
                self.path(child)
            return
        try:
            if forbidden_file(path.name):
                self.flag('FORBIDDEN_FILE')
            self.blob(read_limited(path), path.suffix.lower(), inventory=path.name == 'SHA256SUMS')
        except Exception:
            self.flag('UNREADABLE_OR_OVERSIZE')


def forbidden_file(name):
    parts = PurePosixPath(name).parts
    return any(p.startswith('.') or re.search(r'(^|[-_.])(raw|state|private|local)([-_.]|$)', p, re.I) for p in parts)


def scan_main(argv):
    scanner = Scanner()
    if not argv:
        scanner.flag('NO_INPUT')
    for arg in argv:
        scanner.path(arg)
    # Never emit user-controlled paths, JSON paths, matches, or exceptions.
    print(json.dumps({'result': 'FAIL' if scanner.findings else 'PASS', 'categories': sorted(scanner.findings)}))
    return int(bool(scanner.findings))

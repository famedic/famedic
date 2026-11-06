#!/usr/bin/env python3
"""Independent Python signer. Public synthetic fixtures only; no verifier imports."""
import base64
import hashlib
import hmac
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
REGISTRY = {
    'lookup-synthetic': dict(secret='synthetic-only-lookup-v1-key-000001', profile='lookup-v1',
        integration='synthetic-atix', kind='leo', audience='local-stage', channel='whatsapp', active=True),
    'atix-synthetic': dict(secret='synthetic-only-atix-v2-key-0000001', profile='delegated-v2',
        integration='synthetic-atix', kind='leo', audience='local-stage', channel='whatsapp', active=True),
    'other-synthetic': dict(secret='synthetic-only-other-v2-key-000001', profile='delegated-v2',
        integration='synthetic-other', kind='integration', audience='local-stage', channel='whatsapp', active=True),
    'audience-synthetic': dict(secret='synthetic-only-audience-v2-key-001', profile='delegated-v2',
        integration='synthetic-atix', kind='leo', audience='local-other', channel='whatsapp', active=True),
}

def request(**updates):
    value = dict(audience='local-stage', key_id='atix-synthetic', method='POST',
        path='/api/v1/cart/items', query='', timestamp='1760000000', nonce='synthetic-nonce-0001',
        channel='whatsapp', pat='123|SYNTHETIC_PAT_NOT_VALID_OR_ISSUED', idempotency_key='synthetic-idem-01',
        body_base64=base64.b64encode('{"note":"México","quantity":1}'.encode()).decode())
    value.update(updates)
    return value

def material(r):
    # This signer deliberately does not import/run PHP validation/canonicalization.
    digest = lambda b: hashlib.sha256(b).hexdigest()
    lines = ['ATIX-API-V2', r['audience'], r['key_id'], r['method'].upper(), r['path'],
        digest(r['query'].encode('ascii')), r['timestamp'], r['nonce'], r['channel'],
        '-' if r['pat'] is None else digest(r['pat'].encode('ascii')),
        '-' if r['idempotency_key'] is None else digest(r['idempotency_key'].encode('ascii')),
        digest(base64.b64decode(r['body_base64'], validate=True))]
    return '\n'.join(lines).encode('utf-8')

def signature(r, secret=None):
    key = secret if secret is not None else REGISTRY[r['key_id']]['secret']
    return hmac.new(key.encode('ascii'), material(r), hashlib.sha256).hexdigest()

def vectors():
    inputs = [
        ('unicode-post', request()),
        ('exact-query-get', request(method='GET', path='/api/v1/cart', query='brand=olab&note=a%20b', body_base64='')),
        ('query-plus', request(method='GET', query='note=a+b', body_base64='')),
        ('otp-before-pat', request(path='/api/v1/auth/login/request-code', pat=None, idempotency_key=None)),
        ('raw-binary', request(body_base64=base64.b64encode(bytes([0,1,255,13,10])).decode())),
        ('escaped-path', request(method='DELETE', path='/api/v1/items/caf%C3%A9', body_base64='')),
        ('other-integration', request(key_id='other-synthetic')),
        ('other-audience', request(key_id='audience-synthetic', audience='local-other')),
    ]
    return dict(profile_status='UNAPPROVED_LOCAL_DRAFT', synthetic_only=True, registry=REGISTRY,
        vectors=[dict(id=name, request=r, canonical_base64=base64.b64encode(material(r)).decode(),
            canonical_sha256=hashlib.sha256(material(r)).hexdigest(), signature=signature(r)) for name,r in inputs])

if __name__ == '__main__':
    import sys
    content = json.dumps(vectors(), ensure_ascii=False, indent=2)+'\n'
    target = HERE/'vectors.json'
    if '--check' in sys.argv:
        if not target.exists() or target.read_text() != content: raise SystemExit('Vector fixture mismatch')
        print('Independent vector regeneration matches frozen fixture')
    else:
        target.write_text(content)
        print('Wrote 8 independent public synthetic vectors')

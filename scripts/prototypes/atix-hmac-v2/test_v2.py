#!/usr/bin/env python3
"""Cross-language tests. Every credential/PAT descriptor is public synthetic data."""
import argparse
import base64
import concurrent.futures
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import threading
import time
from generate_vectors import REGISTRY, request, signature, vectors

HERE=Path(__file__).resolve().parent

class Suite:
    def __init__(self, runtime): self.runtime=runtime; self.results=[]; self.sequence=0
    def new_store(self):
        self.sequence+=1
        directory=self.runtime/str(self.sequence);directory.mkdir()
        return str(directory/'nonces.sqlite')
    def payload(self,r=None,store=None,**updates):
        r=r or request()
        result=dict(request=r,signature=signature(r),registry=copy.deepcopy(REGISTRY),
            store=store or self.new_store(),now=1760000000,target_audience=r['audience'],profile='delegated-v2',require_pat=r['pat'] is not None)
        result.update(updates);return result
    def call(self,data):
        env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','HOME':str(self.runtime),
            'FAMEDIC_V2_EPHEMERAL':str(Path(data['store']).parent)}
        proc=subprocess.run(['php','-d','allow_url_fopen=0','-d',
            'disable_functions=fsockopen,pfsockopen,stream_socket_client,socket_connect,curl_exec,curl_multi_exec',
            str(HERE/'cli.php')],input=json.dumps(data),text=True,capture_output=True,env=env,timeout=20)
        if proc.returncode:raise AssertionError('PHP harness error: '+proc.stdout+proc.stderr)
        return json.loads(proc.stdout)
    def case(self,name,body):
        try:
            details=body();self.results.append(dict(name=name,result='PASS',details=details))
        except Exception as e:self.results.append(dict(name=name,result='FAIL',error=str(e)))
    def eq(self,value,expected):
        if value!=expected:raise AssertionError(f'Expected {expected!r}; got {value!r}')
    def denied(self,data,code):
        out=self.call(data);self.eq(out,dict(result='DENIED',code=code));return out
    def accepted(self,data):
        out=self.call(data);self.eq(out['result'],'PROOF_ACCEPTED');self.eq(out['patient_authenticated'],False)
        self.eq(out['payment_permission_granted'],False);return out
    def run(self):
        fixture=json.loads((HERE/'vectors.json').read_text())
        self.case('Frozen independent vectors regenerate identically',lambda:self.eq(fixture,vectors()))
        for v in fixture['vectors']:
            def positive(v=v):
                data=self.payload(v['request'],signature=v['signature']);out=self.accepted(data)
                self.eq(out['canonical_base64'],v['canonical_base64']);self.eq(out['canonical_sha256'],v['canonical_sha256'])
                return {'vector':v['id'],'canonical_sha256':out['canonical_sha256']}
            self.case('Vector '+v['id'],positive)
        mutations={'method':'PUT','path':'/api/v1/cart/different','query':'brand=olab',
            'body_base64':base64.b64encode(b'{"quantity":2}').decode(),
            'pat':'456|DIFFERENT_SYNTHETIC_PAT','idempotency_key':'different-idem-key','nonce':'different-nonce-01','timestamp':'1760000001'}
        for field,value in mutations.items():
            def tamper(field=field,value=value):
                d=self.payload();d['request'][field]=value;return self.denied(d,'V2_SIGNATURE_INVALID')
            self.case('Tamper '+field+' invalidates original signature',tamper)
        for field,value in [('audience','local-other'),('channel','telegram')]:
            def context(field=field,value=value):
                d=self.payload();d['request'][field]=value;return self.denied(d,'V2_CONTEXT_REJECTED')
            self.case('Wrong '+field+' rejected',context)
        def wrong_selector():
            d=self.payload();d['request']['key_id']='other-synthetic';return self.denied(d,'V2_SIGNATURE_INVALID')
        self.case('Changing key selector cannot impersonate other integration',wrong_selector)
        self.case('V1 credential cannot verify V2',lambda:self.denied(self.payload(request(key_id='lookup-synthetic')),'V2_CREDENTIAL_REJECTED'))
        self.case('V2 verifier has no V1 profile fallback',lambda:self.denied(self.payload(profile='lookup-v1'),'V2_CREDENTIAL_REJECTED'))
        def v1_signature():
            import hmac
            r=request();msg='\n'.join(['POST','/api/v1/integrations/atix/phone-lookups',r['timestamp'],r['nonce'],hashlib.sha256(base64.b64decode(r['body_base64'])).hexdigest()])
            sig=hmac.new(REGISTRY['lookup-synthetic']['secret'].encode(),msg.encode(),hashlib.sha256).hexdigest()
            return self.denied(self.payload(r,signature=sig),'V2_SIGNATURE_INVALID')
        self.case('Actual V1 material and secret never fallback in V2',v1_signature)
        for key in ['lookup-synthetic','other-synthetic','audience-synthetic']:
            self.case('Secret separation '+key,lambda key=key:self.denied(self.payload(signature=signature(request(),REGISTRY[key]['secret'])),'V2_SIGNATURE_INVALID'))
        def duplicate_secret():
            d=self.payload();d['registry']['other-synthetic']['secret']=d['registry']['atix-synthetic']['secret']
            return self.denied(d,'V2_SHARED_SECRET_FORBIDDEN')
        self.case('Registry rejects same secret across purpose or consumer',duplicate_secret)
        def disabled():
            d=self.payload();d['registry']['atix-synthetic']['active']=False;return self.denied(d,'V2_CREDENTIAL_REJECTED')
        self.case('Revoked integration credential fails closed',disabled)
        def other_audience():
            d=self.payload(request(key_id='audience-synthetic',audience='local-other'));d['target_audience']='local-stage'
            return self.denied(d,'V2_CONTEXT_REJECTED')
        self.case('Correct other-audience signature cannot cross deployment',other_audience)
        self.case('Missing PAT denied when operation requires it',lambda:self.denied(self.payload(request(pat=None),require_pat=True),'V2_PAT_REQUIRED'))
        for path in ['/api/../cart','/api/%2E%2E/cart','/api//cart','/api/%2Fcart','/api/%252Fcart','/api/cart?x=1','/api/cart\nforged','/api/%xx']:
            self.case('Reject ambiguous path '+repr(path),lambda path=path:self.denied(self.payload(request(path=path)),'V2_AMBIGUOUS_TARGET'))
        for query in ['a=1&a=2','a=1&%61=2','a[]=1','_method=DELETE','a.b=1','a=1\nnonce=2','a=%xx']:
            self.case('Reject ambiguous query '+repr(query),lambda query=query:self.denied(self.payload(request(query=query)),'V2_AMBIGUOUS_QUERY'))
        def reordered():
            d=self.payload(request(query='a=1&b=2'));d['request']['query']='b=2&a=1';return self.denied(d,'V2_SIGNATURE_INVALID')
        self.case('Query order is exact bytes',reordered)
        def plus_space():
            d=self.payload(request(query='note=a+b'));d['request']['query']='note=a%20b';return self.denied(d,'V2_SIGNATURE_INVALID')
        self.case('Plus versus percent-space cannot change after signing',plus_space)
        self.case('Empty idempotency differs from absent',lambda:self.denied(self.payload(request(idempotency_key='')),'V2_MALFORMED_IDEMPOTENCY'))
        self.case('Timestamp leading zero rejected',lambda:self.denied(self.payload(request(timestamp='01760000000')),'V2_MALFORMED'))
        self.case('CRLF cannot delimit extra canonical fields',lambda:self.denied(self.payload(request(audience='local-stage\nforged')),'V2_MALFORMED'))
        self.case('Uppercase signature not silently negotiated',lambda:self.denied(self.payload(signature=signature(request()).upper()),'V2_SIGNATURE_INVALID'))
        self.case('Invalid margin configuration rejected',lambda:self.denied(self.payload(margin=0),'V2_INVALID_CONFIG'))
        self.case('Unknown header-like field cannot become authority',lambda:self.denied(self.payload(dict(request(),consumer_kind='native')),'V2_MALFORMED'))
        for tol in [1,300,900]:
            def horizon(tol=tol):
                start=1760000000;r=request(timestamp=str(start+tol));d=self.payload(r,tolerance=tol)
                first=self.accepted(d);self.eq(first['nonce_expires_at'],start+2*tol+5)
                for offset in [tol+1,2*tol]:
                    d['now']=start+offset;self.denied(d,'V2_NONCE_REPLAY')
                d['now']=start+2*tol+1;self.denied(d,'V2_TIMESTAMP_EXPIRED')
                return {'skew':tol,'nonce_expires_at':first['nonce_expires_at'],'full_window_replay_rejected':True}
            self.case('Future timestamp full horizon '+str(tol),horizon)
        def past_boundary():
            r=request(timestamp=str(1760000000-300));d=self.payload(r);out=self.accepted(d)
            self.eq(out['nonce_expires_at'],1760000005);d['now']+=1;return self.denied(d,'V2_TIMESTAMP_EXPIRED')
        self.case('Past timestamp exact boundary and next second',past_boundary)
        def duplicate():
            d=self.payload();self.accepted(d);return self.denied(d,'V2_NONCE_REPLAY')
        self.case('Sequential nonce duplicate denied',duplicate)
        def readonly():
            d=self.payload(storage_mode='query_only');self.denied(d,'V2_STORAGE_UNAVAILABLE')
            d['storage_mode']='normal';return self.accepted(d)
        self.case('Actual SQLite write failure denies without claiming nonce',readonly)
        def bad_database():
            d=self.payload();Path(d['store']).write_bytes(b'not-a-database')
            return self.denied(d,'V2_STORAGE_UNAVAILABLE')
        self.case('Actual corrupt storage fails closed',bad_database)
        def symlink():
            d=self.payload();sentinel=self.runtime/'synthetic-sentinel';sentinel.write_bytes(b'unchanged')
            Path(d['store']).symlink_to(sentinel);self.denied(d,'V2_UNSAFE_LOCAL_STORAGE');self.eq(sentinel.read_bytes(),b'unchanged')
        self.case('Nonce storage symlink cannot escape ephemeral target',symlink)
        def busy():
            d=self.payload();self.accepted(d)
            d['request']=request(nonce='synthetic-locked-nonce');d['signature']=signature(d['request'])
            con=sqlite3.connect(d['store']);con.execute('BEGIN IMMEDIATE')
            try:self.denied(d,'V2_STORAGE_UNAVAILABLE')
            finally:con.rollback();con.close()
            return self.accepted(d)
        self.case('Real database lock timeout denies then recovers',busy)
        def concurrent_claim():
            store=self.new_store();self.accepted(self.payload(request(nonce='synthetic-init-nonce'),store=store))
            d=self.payload(store=store);barrier=threading.Barrier(16)
            def job(_):barrier.wait(timeout=10);return self.call(d)
            with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:out=list(pool.map(job,range(16)))
            winners=sum(x.get('result')=='PROOF_ACCEPTED' for x in out)
            rejected=sum(x.get('code')=='V2_NONCE_REPLAY' for x in out)
            self.eq((winners,rejected),(1,15))
            return {'independent_php_processes':16,'accepted':winners,'nonce_replay':rejected}
        self.case('Atomic claim across 16 PHP processes',concurrent_claim)
        def retry():
            first=self.accepted(self.payload())
            d=self.payload(request(nonce='synthetic-new-retry-nonce'));second=self.accepted(d)
            self.eq(first['integration'],second['integration']);return {'fresh_proof_accepted':True,'business_idempotency_not_implemented':True}
        self.case('Fresh nonce proof allows same semantic retry without granting business',retry)
        def bind_fixture(**updates):
            b=dict(active=True,expires_at=1760000300,user_id=7,integration='synthetic-atix',channel='whatsapp',audience='local-stage',pat_digest=hashlib.sha256(request()['pat'].encode()).hexdigest())
            b.update(updates);return b
        def binding_absent():
            out=self.accepted(self.payload());self.eq(out['binding_fixture_matches'],False);return out
        self.case('Valid signature does not authenticate patient or supply binding',binding_absent)
        def missing_binding_denial():
            out=self.accepted(self.payload(user_id=7,check_payment_policy=True))
            self.eq(out['existing_pure_payment_policy_denial'],'CONSUMER_NOT_AUTHORIZED')
            return {'real_proof':True,'missing_binding_denied_by_existing_policy':True,'patient_authentication_not_run':True}
        self.case('Valid real proof without binding is denied by existing payment policy',missing_binding_denial)
        def matching_binding():
            out=self.accepted(self.payload(binding=bind_fixture(),user_id=7));self.eq(out['binding_fixture_matches'],True)
            self.eq(out['patient_authenticated'],False);return {'binding_fixture_only':True,'Sanctum_authentication_executed':False}
        self.case('Separate matching synthetic binding is not patient authentication',matching_binding)
        for name,update in [('wrong-user',{'user_id':8}),('wrong-integration',{'integration':'synthetic-other'}),('wrong-channel',{'channel':'telegram'}),('revoked',{'active':False}),('expired',{'expires_at':1760000000}),('wrong-pat',{'pat_digest':'0'*64})]:
            def mismatched(update=update):
                out=self.accepted(self.payload(binding=bind_fixture(**update),user_id=7));self.eq(out['binding_fixture_matches'],False)
            self.case('Binding independent '+name,mismatched)
        def other_consumer():
            out=self.accepted(self.payload(request(key_id='other-synthetic'),binding=bind_fixture(),user_id=7))
            self.eq(out['integration'],'synthetic-other');self.eq(out['binding_fixture_matches'],False)
            return {'authenticated_synthetic_integration':'synthetic-other','native_adapter_installed':False}
        self.case('Other valid integration cannot impersonate ATIX binding',other_consumer)
        capability='akubica:payment-link:create'
        for abilities in [['akubica:read'],['*'],['*',capability],[capability]]:
            def policy(abilities=abilities):
                out=self.accepted(self.payload(binding=bind_fixture(),user_id=7,check_payment_policy=True,abilities=abilities,
                    consumer_capabilities=[capability],delegation_capabilities=[capability]))
                self.eq(out['existing_pure_payment_policy_denial'],'LEO_PAYMENT_LINK_FORBIDDEN')
                return {'pure_existing_policy':True,'abilities':abilities,'business_action_executed':False}
            self.case('ATIX cannot pay even with abilities '+repr(abilities),policy)
        return self.results

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--report',type=Path,required=True);args=parser.parse_args()
    if args.report.exists():raise SystemExit('Refusing to overwrite evidence')
    with tempfile.TemporaryDirectory(prefix='famedic-v2-prototype-') as tmp:
        results=Suite(Path(tmp)).run()
    data=dict(scope='ISOLATED_PROTOTYPE_NOT_P01_P12',results=results,
        counts={k:sum(x['result']==k for x in results) for k in ['PASS','FAIL']},
        native_operational_identity=False,real_PAT_issued=False,temporary_sqlite_destroyed=True)
    args.report.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'counts':data['counts'],'failures':[x for x in results if x['result']=='FAIL']}))
    raise SystemExit(bool(data['counts']['FAIL']))

#!/usr/bin/env python3
"""Actual V1/PDO tests. Docker pinned to local Unix socket; no pulls or remote DB."""
import argparse,concurrent.futures,datetime,hashlib,hmac,json,os,subprocess,tempfile,time,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
WORKER=ROOT/'scripts/tests/h04-v1/service_worker.php'
MIGRATION=ROOT/'database/migrations/2026_10_21_140000_create_atix_sandbox_nonces_table.php'
DOCKER=['docker','--host','unix:///var/run/docker.sock']
ENV={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'}
SECRET='public-synthetic-H04-only'
PASSWORD='PUBLIC_SYNTHETIC_H04_ONLY'
EPOCH=1760000000

def cmd(args,**kwargs):return subprocess.run(args,env=ENV,text=True,capture_output=True,**kwargs)
def docker(*args,**kwargs):return cmd(DOCKER+list(args),**kwargs)
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--report',type=Path,required=True);args=ap.parse_args()
    if args.report.exists():raise SystemExit('Evidence already exists')
    runid=uuid.uuid4().hex;label='famedic.phase2b1f='+runid;results=[];metadata={};cleanup=False
    def test(name,fn):
        try:details=fn();results.append(dict(name=name,result='PASS',details=details))
        except Exception as exc:results.append(dict(name=name,result='FAIL',error=str(exc)))
        print(json.dumps({'check':name,'result':results[-1]['result']}),flush=True)
    def eq(a,b):
        if a!=b:raise AssertionError('Expected '+repr(b)+'; got '+repr(a))
    with tempfile.TemporaryDirectory(prefix='famedic-h04-storage-') as tmp:
        rt=Path(tmp);(rt/'socket').mkdir(mode=0o777);os.chmod(rt/'socket',0o777)
        (rt/'control').mkdir(mode=0o777);os.chmod(rt/'control',0o777)
        sqlite_path=rt/'nonces.sqlite';sqlite_path.touch()
        sqlite_db=dict(driver='sqlite',database=str(sqlite_path),prefix='',busy_timeout=5000)
        mysql_db=dict(driver='mysql',unix_socket='/phase-socket/mysql.sock',database='h04_testing',
            username='root',password=PASSWORD,charset='utf8mb4',collation='utf8mb4_unicode_ci',prefix='',timezone='+00:00')
        php_mounts=[]
        for source,target in [(ROOT/'vendor','/phase-source/vendor'),(ROOT/'app','/phase-source/app'),
            (WORKER,'/experiment/worker.php'),(MIGRATION,'/experiment/nonce-migration.php')]:
            php_mounts+=['--mount',f'type=bind,src={source},dst={target},readonly']
        php_mounts+=['--mount',f'type=bind,src={rt/"socket"},dst=/phase-socket,readonly',
            '--mount',f'type=bind,src={rt/"control"},dst=/control']
        def invoke(data,driver='sqlite'):
            if driver=='sqlite':
                env=dict(ENV,FAMEDIC_H04_SOURCE_ROOT=str(ROOT),FAMEDIC_H04_NONCE_MIGRATION=str(MIGRATION),FAMEDIC_H04_STORAGE_ROOT=str(rt))
                out=subprocess.run(['php','-d','allow_url_fopen=0','-d',
                    'disable_functions=fsockopen,pfsockopen,stream_socket_client,socket_connect,curl_exec,curl_multi_exec,mail',str(WORKER)],
                    input=json.dumps(data),env=env,text=True,capture_output=True,timeout=50)
            else:
                name='h04-php-'+uuid.uuid4().hex[:12]
                out=docker('run','--interactive','--rm','--pull=never','--name',name,'--label',label,'--network','none',
                    '--read-only','--cap-drop','ALL','--security-opt','no-new-privileges',
                    '--env','FAMEDIC_H04_SOURCE_ROOT=/phase-source','--env','FAMEDIC_H04_NONCE_MIGRATION=/experiment/nonce-migration.php',
                    *php_mounts,'--entrypoint','php','famedic-app:latest','-d','allow_url_fopen=0','-d',
                    'disable_functions=fsockopen,pfsockopen,stream_socket_client,socket_connect,curl_exec,curl_multi_exec,mail',
                    '/experiment/worker.php',input=json.dumps(data),timeout=55)
            if out.returncode:raise AssertionError('Local worker failed: '+out.stdout+out.stderr[:700])
            decoded=json.loads(out.stdout)
            if decoded.get('candidate_source_verified') is not True:raise AssertionError('Candidate source path proof missing')
            return decoded
        def signed(db,nonce,now=EPOCH,timestamp=None,**extra):
            ts=str(EPOCH+300 if timestamp is None else timestamp);body='{"synthetic":true}'
            text='\n'.join(['POST','/api/v1/integrations/atix/phone-lookups',ts,nonce,hashlib.sha256(body.encode()).hexdigest()])
            d=dict(database=db,now=now,timestamp=ts,nonce=nonce,body=body,
                signature=hmac.new(SECRET.encode(),text.encode(),hashlib.sha256).hexdigest());d.update(extra);return d
        def run_driver(driver,db):
            def horizon():
                records=[]
                for tol in [1,300,900]:
                    d=signed(db,f'{driver}-horizon-{tol}',timestamp=EPOCH+tol,tolerance=tol,ttl=1,inspect=True)
                    first=invoke(d,driver);eq(first['accepted'],True);eq(first['expires_at'],EPOCH+2*tol+1)
                    for offset in [0,tol+1,2*tol]:
                        d['now']=EPOCH+offset;out=invoke(d,driver);eq((out['http'],out['error']),(409,'ATIX_NONCE_REPLAY'));eq(out['row_id'],first['row_id'])
                    d['now']=EPOCH+2*tol+1;eq(invoke(d,driver)['error'],'ATIX_TIMESTAMP_EXPIRED')
                    # Past the stored expiry, no recycling; then clock rollback.
                    future=EPOCH+2*tol+100
                    fresh=signed(db,d['nonce'],now=future,timestamp=future,tolerance=tol,ttl=1,inspect=True)
                    eq(invoke(fresh,driver)['error'],'ATIX_NONCE_REPLAY')
                    d['now']=EPOCH+tol;eq(invoke(d,driver)['error'],'ATIX_NONCE_REPLAY')
                    records.append(dict(tolerance=tol,expiry=first['expires_at'],rollback_protected=True))
                return records
            test(driver+' actual service retention and clock rollback',horizon)
            def race():
                token=uuid.uuid4().hex[:10];go=rt/'control'/('go-'+token);count=8
                def job(i):
                    ready=rt/'control'/f'{token}-{i}.ready'
                    params=dict(go_file=str(go) if driver=='sqlite' else '/control/'+go.name,
                        ready_file=str(ready) if driver=='sqlite' else '/control/'+ready.name)
                    return invoke(signed(db,driver+'-concurrent-'+token,**params),driver)
                with concurrent.futures.ThreadPoolExecutor(max_workers=count) as pool:
                    jobs=[pool.submit(job,i) for i in range(count)];deadline=time.monotonic()+40
                    while len(list((rt/'control').glob(token+'-*.ready')))<count:
                        if any(j.done() and j.exception() for j in jobs):
                            go.touch();raise AssertionError('A concurrent worker failed before barrier')
                        if time.monotonic()>deadline:
                            go.touch();raise AssertionError('Concurrent workers did not reach barrier')
                        time.sleep(.05)
                    ready_count=len(list((rt/'control').glob(token+'-*.ready')));go.touch();out=[j.result() for j in jobs]
                accepted=sum(x['accepted'] for x in out);duplicates=sum(x['error']=='ATIX_NONCE_REPLAY' for x in out)
                eq((accepted,duplicates),(1,7))
                return dict(real_processes=8,ready_before_release=ready_count,accepted=accepted,replay_denied=duplicates,outputs=out)
            test(driver+' real concurrent atomic unique claim',race)
            def failure():
                d=signed(db,driver+'-readonly-storage',timestamp=EPOCH,
                    fault='query_only' if driver=='sqlite' else 'mysql_readonly')
                out=invoke(d,driver);eq((out['http'],out['error']),(503,'ATIX_INFRASTRUCTURE_UNAVAILABLE'))
                d.pop('fault');eq(invoke(d,driver)['accepted'],True)
                return dict(actual_readonly_failure_rejected=out,retry_after_recovery_accepted=True)
            test(driver+' actual write failure and recovery',failure)
        try:
            metadata['sqlite']=invoke(dict(action='init',database=sqlite_db))
            run_driver('sqlite',sqlite_db)
            # Require preinstalled images; --pull=never and local socket prohibit downloads/remotes.
            for image in ['mysql:8.0','famedic-app:latest']:
                image_info=docker('image','inspect','--format','{{.Id}}',image,timeout=10)
                if image_info.returncode:raise RuntimeError('Required local image unavailable')
                metadata[image]=image_info.stdout.strip()
            mysql_name='h04-mysql-'+runid[:12]
            launched=docker('run','--rm','--pull=never','--detach','--name',mysql_name,'--label',label,
                '--network','none','--read-only','--security-opt','no-new-privileges',
                '--tmpfs','/var/lib/mysql:rw,nosuid,size=768m','--tmpfs','/var/run/mysqld:rw,nosuid,size=16m',
                '--tmpfs','/tmp:rw,nosuid,size=16m','--mount',f'type=bind,src={rt/"socket"},dst=/phase-socket',
                '--env','MYSQL_ROOT_PASSWORD='+PASSWORD,'--env','MYSQL_DATABASE=h04_testing',
                'mysql:8.0','--skip-networking','--mysqlx=OFF','--skip-log-bin',
                '--socket=/phase-socket/mysql.sock','--innodb-buffer-pool-size=64M',timeout=15)
            if launched.returncode:raise RuntimeError('Cannot start ephemeral isolated MySQL: '+launched.stderr[:600])
            deadline=time.monotonic()+75;last_readiness_error=''
            while True:
                try:
                    pid1=docker('exec',mysql_name,'cat','/proc/1/comm',timeout=5)
                    if pid1.returncode or pid1.stdout.strip()!='mysqld':raise RuntimeError('MySQL entrypoint still initializing')
                    metadata['mysql']=invoke(dict(action='init',database=mysql_db),'mysql');break
                except Exception as exc:
                    last_readiness_error=str(exc)
                    if time.monotonic()>deadline:raise RuntimeError('Ephemeral MySQL readiness failed: '+last_readiness_error)
                    time.sleep(.5)
            info=docker('inspect',mysql_name,timeout=10)
            inspected=json.loads(info.stdout)[0]
            eq(inspected['HostConfig']['NetworkMode'],'none');eq(inspected['HostConfig']['PortBindings'],{})
            eq(inspected['HostConfig']['ReadonlyRootfs'],True)
            eq(inspected['HostConfig']['Tmpfs']['/var/lib/mysql'],'rw,nosuid,size=768m')
            metadata['isolation']=dict(network='none',published_ports=[],database='tmpfs /var/lib/mysql',
                socket='temporary Unix socket only',rootfs='read-only',no_real_env_or_database_mounted=True)
            run_driver('mysql',mysql_db)
            def outage():
                stopped=docker('stop','--time','5',mysql_name,timeout=15);eq(stopped.returncode,0)
                out=invoke(signed(mysql_db,'mysql-real-outage',timestamp=EPOCH),'mysql')
                eq((out['http'],out['error']),(503,'ATIX_INFRASTRUCTURE_UNAVAILABLE'));return out
            test('mysql actual server outage fails closed',outage)
        except Exception as exc:results.append(dict(name='Storage harness setup',result='FAIL',error=str(exc)))
        finally:
            # Only containers carrying this run's unique label, never project services.
            listed=docker('ps','-aq','--filter','label='+label,timeout=10)
            ids=listed.stdout.split()
            if ids:
                checked=docker('inspect',*ids,timeout=10)
                for item in json.loads(checked.stdout):eq(item['Config']['Labels'].get('famedic.phase2b1f'),runid)
                removed=docker('rm','--force','--volumes',*ids,timeout=20)
                if removed.returncode:raise RuntimeError('Ephemeral container cleanup failed')
            listed=docker('ps','-aq','--filter','label='+label,timeout=10);cleanup=listed.returncode==0 and not listed.stdout.strip()
    report=dict(phase='CIERRE_ACELERADO_H04_CANDIDATE',scope='CLEAN_CANDIDATE_REAL_SQLITE_AND_EPHEMERAL_MYSQL',metadata=metadata,results=results,
        counts={state:sum(x['result']==state for x in results) for state in ['PASS','FAIL']},
        ephemeral_containers_removed=cleanup,temporary_storage_removed=True,no_dependency_downloads=True,candidate_source_reflection_verified_on_every_worker=True,application_root=str(ROOT),
        real_credentials_used=False,remote_or_persistent_database_used=False)
    report['result']='PASS' if cleanup and report['counts']=={'PASS':7,'FAIL':0} else 'FAIL'
    args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(dict(result=report['result'],counts=report['counts'],metadata=metadata,
        failures=[x for x in results if x['result']=='FAIL'],cleanup=cleanup)),flush=True)
    return report['result']!='PASS'
if __name__=='__main__':raise SystemExit(main())

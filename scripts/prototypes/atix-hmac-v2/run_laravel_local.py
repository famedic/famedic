#!/usr/bin/env python3
# Existing V1 suites plus H04: empty env, RAM database and outbound guards.
import argparse,datetime,hashlib,json,shutil,subprocess,tempfile
from pathlib import Path
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[3]
HERE=Path(__file__).resolve().parent
EXPECTED='523a566bab22fffb4c0ad8fbb29d79cc4823e99769494b1fd135f6639094f9a4'
EXCLUDED=['2026_10_21_142000_relax_atix_operation_unique_indexes.php']
def quote(v):return "'"+str(v).replace('\\','\\\\').replace("'","\\'")+"'"
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--report',type=Path,required=True);parser.add_argument('--only',action='append',choices=['v1_phone_lookups_standard','v1_phone_lookups_isolated','H04_real_V1','existing_pure_payment_policy','existing_payload_fingerprint']);args=parser.parse_args()
    if args.report.exists():raise SystemExit('Refusing to overwrite evidence')
    if digest(ROOT/'docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml')!=EXPECTED:raise SystemExit('Contract mismatch')
    receipt=dict(phase='2B-1E',started_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
        scope='EPHEMERAL_LOCAL_REAL_V1_AND_PURE_POLICY_NOT_V2_RUNTIME_INTEGRATION',database='sqlite :memory:',
        credentials='PUBLIC_SYNTHETIC_TEST_FIXTURES_ONLY',real_env_loaded=False,external_providers_allowed=False,
        operational_V1_modified=False,migrations='Existing copies applied ONLY to RAM DB; no new migration',
        sqlite_test_copy_exclusion=EXCLUDED,groups={})
    with tempfile.TemporaryDirectory(prefix='famedic-v2-laravel-') as tmp:
        rt=Path(tmp)
        for f in ['bootstrap','database/migrations','storage/logs','storage/framework/views','storage/framework/sessions','storage/framework/cache']:(rt/f).mkdir(parents=True,exist_ok=True)
        (rt/'.env.synthetic').write_text('# Empty synthetic environment\n')
        for f in (ROOT/'database/migrations').iterdir():
            if f.is_file() and f.name not in EXCLUDED:shutil.copyfile(f,rt/'database/migrations'/f.name)
        b='<?php\n$app=require '+quote(ROOT/'bootstrap/app.php')+';\n'
        b+='$app->useEnvironmentPath('+quote(rt)+");\n$app->loadEnvironmentFrom('.env.synthetic');\n"
        b+='$app->useStoragePath('+quote(rt/'storage')+');\n$app->useDatabasePath('+quote(rt/'database')+');\n'
        b+=r'''$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class,function($app){
    $app['config']->set('database.default','sqlite');
    $app['config']->set('database.connections',['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]);
    $app['config']->set('services.activecampaign.endpoint','https://synthetic.invalid');
    $app['config']->set('services.activecampaign.token','synthetic-no-credential');
    $app['config']->set('mail.default','array');
    $app['config']->set('queue.default','sync');
});
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class,function($app){
    if (!$app->environment('testing') || $app['config']->get('database.default')!=='sqlite'
        || $app['config']->get('database.connections.sqlite.database')!==':memory:') {
        throw new \RuntimeException('Unsafe ephemeral database target');
    }
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    if (getenv('FAMEDIC_V2_PREPARE_SCHEMA')==='1') {
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--force'=>true]);
    }
});
return $app;
'''
        (rt/'bootstrap/app.php').write_text(b)
        xml=ET.parse(ROOT/'phpunit.xml');node=xml.getroot();node.set('bootstrap',str(ROOT/'vendor/autoload.php'));node.set('cacheDirectory',str(rt/'phpunit-cache'))
        for suites in node.findall('testsuites'):
            for suite in suites:
                for directory in suite:directory.text=str(ROOT/directory.text)
        overrides=dict(APP_BASE_PATH=str(rt),APP_ENV='testing',APP_KEY='base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=',
            DB_CONNECTION='sqlite',DB_DATABASE=':memory:',DB_HOST='',DB_USERNAME='',DB_PASSWORD='',LOG_CHANNEL='single',
            MAIL_MAILER='array',QUEUE_CONNECTION='sync',SESSION_DRIVER='array',CACHE_STORE='array',SCOUT_DRIVER='null',
            APP_CONFIG_CACHE=str(rt/'config.php'),APP_ROUTES_CACHE=str(rt/'routes.php'),APP_PACKAGES_CACHE=str(rt/'packages.php'),
            APP_SERVICES_CACHE=str(rt/'services.php'),FAMEDIC_V2_EPHEMERAL=str(rt),FAMEDIC_V2_H04_REPORT=str(rt/'h04.jsonl'))
        for key,value in overrides.items():
            php=node.find('php');element=php.find("env[@name='"+key+"']")
            if element is None:element=ET.SubElement(php,'env',{'name':key})
            element.set('value',value);element.set('force','true')
        cfg=rt/'phpunit.xml';xml.write(cfg,encoding='utf-8',xml_declaration=True)
        env=dict(HOME=str(rt),PATH='/usr/bin:/bin',LANG='C.UTF-8',APP_BASE_PATH=str(rt),APP_ENV='testing',PYTHONDONTWRITEBYTECODE='1')
        cmd=['php','-d','allow_url_fopen=0','-d','disable_functions=fsockopen,pfsockopen,stream_socket_client,socket_connect,curl_exec,curl_multi_exec,mail',
            str(ROOT/'vendor/bin/pest'),'--configuration',str(cfg),'--colors=never','--do-not-cache-result','--display-warnings']
        groups={'v1_phone_lookups_standard':[ROOT/'tests/Feature/Api/V1/AtixPhoneLookupTest.php'],
            'v1_phone_lookups_isolated':[ROOT/'tests/Feature/Api/V1'/f for f in ['AtixPhoneLookupIsolatedTest.php','AtixPhoneLookupRc1Phase1IsolatedTest.php']],
            'H04_real_V1':[HERE/'H04RealV1Test.php'],
            'existing_pure_payment_policy':[ROOT/'tests/Unit/Api/V1/ConsumerAccessPolicyTest.php'],
            'existing_payload_fingerprint':[ROOT/'tests/Unit/Services/Api/V1/Integrations/AtixPayloadFingerprintTest.php']}
        for name,files in groups.items():
            if args.only and name not in args.only:continue
            junit=rt/(name+'.xml');command=cmd+[str(f) for f in files]+['--log-junit',str(junit)]
            child_env=dict(env)
            if name in {'v1_phone_lookups_isolated','H04_real_V1'}:child_env['FAMEDIC_V2_PREPARE_SCHEMA']='1'
            result=subprocess.run(command,cwd=ROOT,env=child_env,capture_output=True,text=True,timeout=180);cases=[]
            if junit.exists():
                for c in ET.parse(junit).getroot().iter('testcase'):
                    tags={x.tag for x in c};status='ERROR' if 'error' in tags else 'FAIL' if 'failure' in tags else 'BLOCKED' if 'skipped' in tags else 'PASS'
                    cases.append(dict(name=c.get('name'),test_class=c.get('classname'),result=status,
                        problem='\n'.join(x.text or '' for x in c if x.tag in {'error','failure','skipped'})))
            counts={s:sum(c['result']==s for c in cases) for s in ['PASS','FAIL','ERROR','BLOCKED']}
            receipt['groups'][name]=dict(command=[str(x).replace(str(rt),'<EPHEMERAL>') for x in command],returncode=result.returncode,
                counts=counts,cases=cases,output=result.stdout+result.stderr,junit_sha256=digest(junit) if junit.exists() else None)
            print(json.dumps(dict(group=name,returncode=result.returncode,counts=counts)),flush=True)
        obs=rt/'h04.jsonl';receipt['H04_observations']=[json.loads(x) for x in obs.read_text().splitlines()] if obs.exists() else []
    receipt['ephemeral_runtime_removed']=True
    receipt['contract_unchanged']=digest(ROOT/'docs/Akubica/akubica-openapi-v1.3.0-rc1.1.yaml')==EXPECTED
    receipt['finished_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    receipt['result']='PASS' if receipt['contract_unchanged'] and all(g['returncode']==0 and sum(g['counts'].values())>0 for g in receipt['groups'].values()) else 'FAIL'
    args.report.write_text(json.dumps(receipt,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(dict(result=receipt['result'],report=str(args.report))),flush=True)
    return receipt['result']!='PASS'
if __name__=='__main__':raise SystemExit(main())

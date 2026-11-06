<?php
declare(strict_types=1);

namespace App\Services\Api\V1\Integrations {
    // Test-only audit sink and MAC spy. Authentication service/middleware stay real.
    final class AtixAuditRecorder {
        public array $events=[];
        public function record($request, string $outcome, int $status, ?string $code, array $metadata=[]): void {
            $this->events[]=['status'=>$status,'code'=>$code];
        }
    }
    function hash_hmac(string $algo, string $data, string $key, bool $binary=false): string {
        $GLOBALS['P005_MAC_CALLS']++;
        return \hash_hmac($algo,$data,$key,$binary);
    }
}
namespace App\Http\Responses {
    // Test-only HTTP envelope; no app bootstrap, logger, providers or database.
    final class ApiResponse {
        public static function error(string $code,string $message,int $status=400,?array $fields=null,mixed $details=null,?bool $retryable=null): \Illuminate\Http\JsonResponse {
            return new \Illuminate\Http\JsonResponse(['error'=>['code'=>$code]],$status);
        }
    }
}
namespace {
    require dirname(__DIR__,2).'/vendor/autoload.php';
    require dirname(__DIR__).'/atix-key-certification.php';
    use Famedic\AdministrativeCertification\AtixKeyCertification as Verifier;
    use App\Services\Api\V1\Integrations\AtixHmacService;
    use App\Services\Api\V1\Integrations\AtixAuditRecorder;
    use App\Http\Middleware\Api\V1\AuthenticateAtixHmac;
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Facade;

    $container=new Container;Container::setInstance($container);Facade::setFacadeApplication($container);
    $container->instance('config',new Repository([]));
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1760000000));
    $rows=[];
    $check=static function(bool $condition): void {if(!$condition)throw new \RuntimeException('SYNTHETIC_ASSERTION_FAILED');};
    $test=static function(string $name,callable $run) use (&$rows): void {
        try{$run();$rows[]=['test'=>$name,'result'=>'PASS'];}
        catch(\Throwable){$rows[]=['test'=>$name,'result'=>'FAIL','category'=>'SYNTHETIC_ASSERTION_FAILED'];}
    };
    $expected=static fn(bool $old,bool $new,bool $fallback,bool $cache)=>[
        'OLD_KEY_CONFIGURED'=>$old,'NEW_KEY_CONFIGURED'=>$new,'FALLBACK_DETECTED'=>$fallback,'CONFIG_CACHE_ACTIVE'=>$cache];
    $fixture='public-synthetic-fixture-only';
    $matrix=[
        ['single_current',['synthetic-current'=>$fixture],false,true,false,true],
        ['old_and_current',['synthetic-retired'=>$fixture,'synthetic-current'=>$fixture],true,true,true,true],
        ['only_old',['synthetic-retired'=>$fixture],true,false,true,true],
        ['empty_map',[],false,false,false,true],
        ['additional_key',['synthetic-current'=>$fixture,'synthetic-extra'=>$fixture],false,true,true,true],
        ['cache_inactive',['synthetic-current'=>$fixture],false,true,false,false],
        ['new_empty',['synthetic-current'=>''],false,false,false,true],
        ['old_empty_still_present',['synthetic-retired'=>'','synthetic-current'=>$fixture],true,true,true,true],
    ];
    foreach($matrix as [$name,$keys,$old,$new,$fallback,$cache]){
        $test($name,function()use($keys,$old,$new,$fallback,$cache,$check,$expected){
            $actual=Verifier::evaluate($keys,'synthetic-retired','synthetic-current',$cache);
            $check($actual===$expected($old,$new,$fallback,$cache));
            $check(count(array_filter($actual,'is_bool'))===4);
        });
    }
    $test('private_identifiers_and_fixture_values_not_in_output',function()use($fixture,$check){
        $encoded=json_encode(Verifier::evaluate(['synthetic-current'=>$fixture],'synthetic-retired','synthetic-current',true));
        foreach(['synthetic-current','synthetic-retired',$fixture]as$value)$check(!str_contains($encoded,$value));
    });
    $test('terminal_line_ending_removed_without_identifier_trimming',function()use($fixture,$check){
        $check(Verifier::removeTerminalLineEnding("synthetic-retired\n")==='synthetic-retired');
        $check(Verifier::removeTerminalLineEnding(" synthetic-retired \r\n")===' synthetic-retired ');
        $denied=false;try{Verifier::evaluate(['synthetic-current'=>$fixture],Verifier::removeTerminalLineEnding(" synthetic-retired \n"),'synthetic-current',true);}catch(\Throwable){$denied=true;}$check($denied);
    });
    $test('invalid_or_equal_identifiers_rejected',function()use($fixture,$check){
        foreach([['','synthetic-current'],['same','same'],["control\n",'synthetic-current'],[str_repeat('x',257),'synthetic-current']]as[$old,$new]){
            $denied=false;try{Verifier::evaluate(['synthetic-current'=>$fixture],$old,$new,true);}catch(\Throwable){$denied=true;}$check($denied);
        }
    });
    $test('nonstring_key_values_rejected_without_dump',function()use($check){
        $denied=false;try{Verifier::evaluate(['synthetic-current'=>42],'synthetic-retired','synthetic-current',true);}catch(\Throwable){$denied=true;}$check($denied);
    });
    $test('case_sensitive_key_selection',function()use($fixture,$check,$expected){
        $check(Verifier::evaluate(['SYNTHETIC-CURRENT'=>$fixture],'synthetic-retired','synthetic-current',true)===$expected(false,false,true,true));
    });
    $root=dirname(__DIR__,2);
    $profile=json_decode(file_get_contents(dirname(__DIR__).'/atix-key-certification-profile.json'),true,512,JSON_THROW_ON_ERROR);
    $test('local_source_profile_matches',function()use($root,$profile){Verifier::verifyProfile($root,$profile);});
    $test('source_mismatch_or_missing_profile_not_certified',function()use($root,$profile,$check){
        $bad=$profile;$bad['files'][array_key_first($bad['files'])]=str_repeat('0',64);
        foreach([$bad,[]]as$input){$denied=false;try{Verifier::verifyProfile($root,$input);}catch(\Throwable){$denied=true;}$check($denied);}
    });
    $authenticate=static function(array $keys,string $selector,bool $correlation=true,bool $validShape=false)use($container):array{
        $container['config']->set('atix.sandbox',['keys'=>$keys,'algorithm'=>'sha256','timestamp_tolerance_seconds'=>300,'nonce_ttl_seconds'=>300]);
        $headers=['CONTENT_TYPE'=>'application/json','HTTP_X_INTEGRATION_KEY_ID'=>$selector,
            'HTTP_X_TIMESTAMP'=>$validShape?'1760000000':'deliberately-invalid',
            'HTTP_X_NONCE'=>'synthetic-nonce','HTTP_X_SIGNATURE'=>$validShape?str_repeat('0',64):'deliberately-invalid'];
        if($correlation)$headers['HTTP_X_CORRELATION_ID']='synthetic-correlation';
        $request=\Illuminate\Http\Request::create('/api/v1/integrations/atix/phone-lookups','POST',[],[],[],$headers,'{"synthetic":true}');
        $audit=new AtixAuditRecorder;$service=new AtixHmacService($audit);$middleware=new AuthenticateAtixHmac($service);
        $GLOBALS['P005_MAC_CALLS']=0;$next=0;
        $response=$middleware->handle($request,static function()use(&$next){$next++;return new \Illuminate\Http\JsonResponse([]);});
        return ['http'=>$response->getStatusCode(),'code'=>$response->getData(true)['error']['code']??null,
            'MAC_calls'=>$GLOBALS['P005_MAC_CALLS'],'controller_calls'=>$next,'audit_events'=>count($audit->events)];
    };
    $test('retired_key_rejected_before_timestamp_and_signature',function()use($authenticate,$fixture,$check){
        $r=$authenticate(['synthetic-current'=>$fixture],'synthetic-retired');
        $check($r===['http'=>401,'code'=>'ATIX_INTEGRATION_UNAUTHORIZED','MAC_calls'=>0,'controller_calls'=>0,'audit_events'=>1]);
    });
    $test('retired_key_valid_signature_shape_still_does_not_call_MAC',function()use($authenticate,$fixture,$check){
        $r=$authenticate(['synthetic-current'=>$fixture],'synthetic-retired',true,true);
        $check($r['code']==='ATIX_INTEGRATION_UNAUTHORIZED'&&$r['MAC_calls']===0&&$r['controller_calls']===0);
    });
    $test('active_key_invalid_signature_is_not_retirement_evidence',function()use($authenticate,$fixture,$check){
        $r=$authenticate(['synthetic-current'=>$fixture],'synthetic-current',true,true);
        $check($r['code']==='ATIX_SIGNATURE_INVALID'&&$r['MAC_calls']===1&&$r['controller_calls']===0);
    });
    $test('configured_old_key_invalid_signature_does_not_prove_retirement',function()use($authenticate,$fixture,$check){
        $r=$authenticate(['synthetic-current'=>$fixture,'synthetic-retired'=>$fixture],'synthetic-retired',true,true);
        $check($r['code']==='ATIX_SIGNATURE_INVALID'&&$r['MAC_calls']===1);
    });
    $test('missing_correlation_does_not_prove_retirement',function()use($authenticate,$fixture,$check){
        $r=$authenticate(['synthetic-current'=>$fixture],'synthetic-retired',false,true);
        $check($r['http']===422&&$r['code']==='ATIX_CORRELATION_ID_REQUIRED'&&$r['MAC_calls']===0);
    });
    $test('wildcard_entry_is_not_a_key_resolution_fallback',function()use($authenticate,$fixture,$check){
        $r=$authenticate(['*'=>$fixture],'synthetic-retired',true,true);
        $check($r['code']==='ATIX_INTEGRATION_UNAUTHORIZED'&&$r['MAC_calls']===0);
    });
    Carbon::setTestNow();
    echo json_encode(['scope'=>'SYNTHETIC_ONLY_NO_APP_BOOT_NO_DB_NO_NETWORK','rows'=>$rows,
        'PASS'=>count(array_filter($rows,static fn($r)=>$r['result']==='PASS')),
        'FAIL'=>count(array_filter($rows,static fn($r)=>$r['result']==='FAIL'))],JSON_THROW_ON_ERROR)."\n";
    exit(count(array_filter($rows,static fn($r)=>$r['result']==='FAIL'))?1:0);
}

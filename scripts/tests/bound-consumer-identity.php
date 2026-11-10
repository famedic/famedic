<?php
$root = dirname(__DIR__, 2);
$vendor = getenv('COMPOSER_VENDOR_DIR') ?: $root.'/vendor';
// Domain-only test: no Laravel boot, no environment, database or external I/O.
$loader = require $vendor.'/autoload.php';
$loader->setPsr4('App\\', [$root.'/app']);
$map=[];foreach($loader->getClassMap() as $class=>$file){if(str_starts_with($class,'App\\'))$map[$class]=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';}$loader->addClassMap($map);
use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Contracts\Api\V1\Auth\ConsumerBindingRepository;
use App\Services\Api\V1\Auth\{AuthenticatedBackendClient,BoundConsumerIdentityResolver,ConsumerAuthorizationContext,PaymentLinkAccessPolicy};
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
foreach([BoundConsumerIdentityResolver::class,PaymentLinkAccessPolicy::class,User::class] as $class){if(!str_starts_with((new ReflectionClass($class))->getFileName(),$root.'/'))throw new RuntimeException('Wrong candidate source');}
$rows=[];
function checkCase(string $name, callable $test): void {global $rows;try{$test();$rows[]=['case_id'=>$name,'result'=>'PASS'];}catch(Throwable){$rows[]=['case_id'=>$name,'result'=>'FAIL'];}}
function expectSame($a,$b):void{if($a!==$b)throw new RuntimeException('Synthetic invariant failed');}
function context(string $kind='native', array $over=[]):ConsumerAuthorizationContext{
 return new ConsumerAuthorizationContext(...array_replace(['consumerId'=>'synthetic-client','consumerKind'=>$kind,'channelId'=>'synthetic-channel','delegationId'=>'synthetic-delegation','userId'=>11,'tokenId'=>22,'consumerCapabilities'=>[PaymentLinkAccessPolicy::CAPABILITY],'delegationCapabilities'=>[PaymentLinkAccessPolicy::CAPABILITY],'expiresAt'=>time()+300,'consumerActive'=>true,'channelActive'=>true,'delegationActive'=>true,'bindingActive'=>true],$over));
}
function fixture(?ConsumerAuthorizationContext $context, ?AuthenticatedBackendClient $proof, bool $failure=false):array{
 $user=new User;$user->forceFill(['id'=>11]);$user->exists=true;
 $token=new PersonalAccessToken;$token->forceFill(['id'=>22,'tokenable_id'=>11,'tokenable_type'=>$user->getMorphClass(),'abilities'=>[PaymentLinkAccessPolicy::CAPABILITY]]);$token->exists=true;
 $repo=new class($context,$failure) implements ConsumerBindingRepository{public function __construct(private $context,private $failure){}public function findByTokenId(int $id):?ConsumerAuthorizationContext{if($this->failure)throw new RuntimeException('Synthetic unavailable');return $this->context;}};
 $auth=new class($proof) implements BackendClientAuthenticator{public function __construct(private $proof){}public function authenticate(Request $request):?AuthenticatedBackendClient{return $this->proof;}};
 return [new BoundConsumerIdentityResolver($repo,$auth),$user,$token];
}
$proof=new AuthenticatedBackendClient('synthetic-client','synthetic-channel',time()+300);
checkCase('P12_NATIVE_POLICY_POSITIVE_WITH_TEST_AUTHENTICATOR',function()use($proof){[$r,$u,$t]=fixture(context(),$proof);$i=$r->resolve(Request::create('/'),$u,$t);expectSame('verified',$i->status);expectSame(null,(new PaymentLinkAccessPolicy)->denial($u,$t,$i,time()));});
checkCase('P01_LEO_EXPLICIT_DENY_WITH_TEST_AUTHENTICATOR',function()use($proof){[$r,$u,$t]=fixture(context('leo'),$proof);expectSame('LEO_PAYMENT_LINK_FORBIDDEN',(new PaymentLinkAccessPolicy)->denial($u,$t,$r->resolve(Request::create('/'),$u,$t),time()));});
checkCase('P02_PUBLIC_HEADERS_NOT_PROOF',function(){[$r,$u,$t]=fixture(context(),null);$req=Request::create('/','GET',[],[],[],['HTTP_X_CONSUMER'=>'native','HTTP_X_CHANNEL'=>'synthetic-channel']);expectSame('rejected',$r->resolve($req,$u,$t)->status);});
foreach(['consumerId'=>'other','channelId'=>'other','expiresAt'=>time()-1] as $key=>$value){checkCase('PROOF_MISMATCH_'.$key,function()use($key,$value){$v=['consumerId'=>'synthetic-client','channelId'=>'synthetic-channel','expiresAt'=>time()+300];$v[$key]=$value;[$r,$u,$t]=fixture(context(),new AuthenticatedBackendClient(...$v));expectSame('rejected',$r->resolve(Request::create('/'),$u,$t)->status);});}
foreach(['userId'=>99,'tokenId'=>99,'expiresAt'=>time()-1,'consumerActive'=>false,'channelActive'=>false,'delegationActive'=>false,'bindingActive'=>false] as $key=>$value){checkCase('BINDING_REJECT_'.$key,function()use($proof,$key,$value){[$r,$u,$t]=fixture(context('native',[$key=>$value]),$proof);expectSame('rejected',$r->resolve(Request::create('/'),$u,$t)->status);});}
checkCase('UNCLASSIFIED_IS_NOT_NATIVE',function()use($proof){[$r,$u,$t]=fixture(null,$proof);$i=$r->resolve(Request::create('/'),$u,$t);expectSame('unclassified',$i->status);expectSame('CONSUMER_NOT_AUTHORIZED',(new PaymentLinkAccessPolicy)->denial($u,$t,$i,time()));});
checkCase('REPOSITORY_FAILURE_REJECTS',function()use($proof){[$r,$u,$t]=fixture(context(),$proof,true);expectSame('rejected',$r->resolve(Request::create('/'),$u,$t)->status);});
checkCase('TOKEN_OWNER_MISMATCH_REJECTS',function()use($proof){[$r,$u,$t]=fixture(context(),$proof);$t->tokenable_id=99;expectSame('rejected',$r->resolve(Request::create('/'),$u,$t)->status);});
checkCase('WILDCARD_CANNOT_GRANT_PAYMENT',function()use($proof){[$r,$u,$t]=fixture(context(),$proof);$t->abilities=['*',PaymentLinkAccessPolicy::CAPABILITY];expectSame('CONSUMER_NOT_AUTHORIZED',(new PaymentLinkAccessPolicy)->denial($u,$t,$r->resolve(Request::create('/'),$u,$t),time()));});
checkCase('TOKEN_NAME_CANNOT_GRANT_IDENTITY',function(){[$r,$u,$t]=fixture(context(),null);$t->name='native';expectSame('rejected',$r->resolve(Request::create('/'),$u,$t)->status);});
checkCase('BOUND_ADAPTER_REGISTERED_FAIL_CLOSED_BY_POLICY',function()use($root){$s=file_get_contents($root.'/app/Providers/AppServiceProvider.php');expectSame(true,str_contains($s,'BoundConsumerIdentityResolver'));expectSame(false,str_contains($s,'UnavailableConsumerIdentityResolver'));});

checkCase('LEO_MEMBERSHIP_CAPABILITY_REQUIRES_THREE_LITERAL_GRANTS',function()use($proof){$cap='memberships:read';[$r,$u,$t]=fixture(context('leo',['consumerCapabilities'=>[$cap],'delegationCapabilities'=>[$cap]]),$proof);$t->abilities=[$cap];$i=$r->resolve(Request::create('/'),$u,$t);expectSame(null,(new \App\Services\Api\V1\Auth\ConsumerCapabilityPolicy)->denial($u,$t,$i,$cap,time()));$t->abilities=['*'];expectSame('CONSUMER_NOT_AUTHORIZED',(new \App\Services\Api\V1\Auth\ConsumerCapabilityPolicy)->denial($u,$t,$i,$cap,time()));});
foreach(['payments:create','legacy-benefits:apply',PaymentLinkAccessPolicy::CAPABILITY] as $cap){checkCase('LEO_FORBIDDEN_OPERATION_'.$cap,function()use($proof,$cap){[$r,$u,$t]=fixture(context('leo',['consumerCapabilities'=>[$cap],'delegationCapabilities'=>[$cap]]),$proof);$t->abilities=[$cap];expectSame('CONSUMER_NOT_AUTHORIZED',(new \App\Services\Api\V1\Auth\ConsumerCapabilityPolicy)->denial($u,$t,$r->resolve(Request::create('/'),$u,$t),$cap,time()));});}
checkCase('MISSING_DELEGATION_GRANT_CANNOT_AUTHORIZE_MEMBERSHIP',function()use($proof){$cap='memberships:read';[$r,$u,$t]=fixture(context('leo',['consumerCapabilities'=>[$cap],'delegationCapabilities'=>[]]),$proof);$t->abilities=[$cap];expectSame('CONSUMER_NOT_AUTHORIZED',(new \App\Services\Api\V1\Auth\ConsumerCapabilityPolicy)->denial($u,$t,$r->resolve(Request::create('/'),$u,$t),$cap,time()));});
$fail=count(array_filter($rows,fn($r)=>$r['result']==='FAIL'));echo json_encode(['scope'=>'INDEPENDENT_COMPONENT_TESTS_WITH_SYNTHETIC_AUTHENTICATOR_NOT_P01_P12_HTTP_CERTIFICATION','backend_transport_approved'=>false,'adapter_activated'=>false,'PASS'=>count($rows)-$fail,'FAIL'=>$fail,'rows'=>$rows],JSON_PRETTY_PRINT)."\n";exit($fail?1:0);

<?php
/** Real V1 service and real Eloquent/PDO; only HTTP output/log sinks are local shims. */
declare(strict_types=1);
$root=getenv('FAMEDIC_H04_SOURCE_ROOT');
date_default_timezone_set('UTC');
if (!$root) {throw new RuntimeException('Explicit local source root required');}
// Reuse installed dependencies without loading application helpers from their original checkout.
$vendor=realpath($root.'/vendor');$applicationFiles=[];
foreach(require $vendor.'/composer/autoload_files.php' as $key=>$file){
 if(str_starts_with($file,dirname($vendor).'/app/')){
  $GLOBALS['__composer_autoload_files'][$key]=true;
  $applicationFiles[]=str_replace(dirname($vendor),$root,$file);
 }
}
$loader=require $root.'/vendor/autoload.php';
foreach($applicationFiles as $file){if(!is_file($file))throw new RuntimeException('Missing candidate helper');require_once $file;}
$loader->setPsr4('App\\',[$root.'/app']);
foreach($loader->getClassMap() as$class=>$file){
 if(str_starts_with($class,'App\\'))$loader->addClassMap([$class=>$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php']);
}
foreach(['App\\Services\\Api\\V1\\Integrations\\AtixHmacService'=>'app/Services/Api/V1/Integrations/AtixHmacService.php','App\\Models\\AtixSandboxNonce'=>'app/Models/AtixSandboxNonce.php']as$class=>$relative){
 if((new ReflectionClass($class))->getFileName()!==$root.'/'.$relative)throw new RuntimeException('CANDIDATE_CLASS_PATH_MISMATCH');
}

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use App\Services\Api\V1\Integrations\AtixHmacService;
use App\Models\AtixSandboxNonce;
try {
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $app=new Container;Container::setInstance($app);Facade::setFacadeApplication($app);
    $config=new Repository(['app'=>['key'=>'public-synthetic-app-key-h04-only'],
        'api_v1'=>['audit'=>['enabled'=>false]],
        'atix'=>['sandbox'=>['keys'=>$input['keys'] ?? ['h04-test'=>'public-synthetic-H04-only'],
            'algorithm'=>'sha256','timestamp_tolerance_seconds'=>$input['tolerance'] ?? 300,
            'nonce_ttl_seconds'=>$input['ttl'] ?? 300]]]);
    $app->instance('config',$config);
    $app->instance('date',new \Illuminate\Support\DateFactory);
    $app->instance('log',new class {public function shareContext(array $v): void {} public function error(...$v): void {}});
    $app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,new class {
        public function json($v,$status=200,$headers=[],$options=0) {return new \Illuminate\Http\JsonResponse($v,$status,$headers,$options);}
    });
    $db=$input['database'];
    if(($db['driver']??null)==='mysql'){
      if($root!=='/phase-source'||($db['unix_socket']??null)!=='/phase-socket/mysql.sock'||($db['database']??null)!=='h04_testing'||($db['username']??null)!=='root'||($db['password']??null)!=='PUBLIC_SYNTHETIC_H04_ONLY'||isset($db['host']))throw new RuntimeException('REMOTE_OR_UNEXPECTED_DB_PROHIBITED');
    }elseif(($db['driver']??null)==='sqlite'){
      $allowed=getenv('FAMEDIC_H04_STORAGE_ROOT');
      if(!$allowed||realpath(dirname($db['database']??''))!==realpath($allowed)||basename($db['database'])!=='nonces.sqlite')throw new RuntimeException('UNEXPECTED_SQLITE_TARGET');
    }else{throw new RuntimeException('REMOTE_OR_UNEXPECTED_DB_PROHIBITED');}
    $capsule=new Capsule($app);$capsule->addConnection($db);$capsule->setAsGlobal();$capsule->bootEloquent();
    $app->instance('db',$capsule->getDatabaseManager());
    $app->instance('db.schema',$capsule->getConnection()->getSchemaBuilder());
    if (($input['action'] ?? '')==='init') {
        $migration=require getenv('FAMEDIC_H04_NONCE_MIGRATION');$migration->up();
        $driver=$capsule->getConnection()->getDriverName();
        $version=$capsule->getConnection()->selectOne($driver==='mysql'?'SELECT VERSION() AS version':'SELECT sqlite_version() AS version')->version;
        echo json_encode(['candidate_source_verified'=>true,'initialized'=>true,'driver'=>$driver,'version'=>$version])."\n";exit;
    }
    if (($input['fault'] ?? '')==='query_only') {$capsule->getConnection()->getPdo()->exec('PRAGMA query_only=ON');}
    if (($input['fault'] ?? '')==='mysql_readonly') {
        $pdo=$capsule->getConnection()->getPdo();$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    }
    if (isset($input['go_file'])) {
        $capsule->getConnection()->getPdo();
        file_put_contents($input['ready_file'],'ready');
        $deadline=microtime(true)+40;
        while (!is_file($input['go_file'])) {
            if (microtime(true)>$deadline) {throw new RuntimeException('Local barrier timeout');}
            usleep(1000);clearstatcache();
        }
    }
    Carbon::setTestNow(Carbon::createFromTimestampUTC($input['now']));
    $r=Request::create('/api/v1/integrations/atix/phone-lookups','POST',[],[],[],[
        'CONTENT_TYPE'=>'application/json','HTTP_X_CORRELATION_ID'=>'public-synthetic-h04-worker',
        'HTTP_X_INTEGRATION_KEY_ID'=>$input['key_id'] ?? 'h04-test','HTTP_X_TIMESTAMP'=>$input['timestamp'],
        'HTTP_X_NONCE'=>$input['nonce'],'HTTP_X_SIGNATURE'=>$input['signature']],$input['body']);
    $app->instance('request',$r);
    $response=$app->make(AtixHmacService::class)->authenticate($r);
    $out=['candidate_source_verified'=>true,'accepted'=>$response===null,'http'=>$response?->getStatusCode(),
        'error'=>$response===null?null:$response->getData(true)['error']['code']];
    if ($input['inspect'] ?? false) {
        $row=AtixSandboxNonce::where('integration_key_hash',hash('sha256',$input['key_id'] ?? 'h04-test'))
            ->where('nonce_hash',hash('sha256',$input['nonce']))->first();
        $out['row_id']=$row?->id;$out['expires_at']=$row?->expires_at?->timestamp;
        $out['namespace_rows']=AtixSandboxNonce::where('integration_key_hash',hash('sha256',$input['key_id'] ?? 'h04-test'))->count();
    }
    echo json_encode($out,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    echo json_encode(['worker_error'=>get_class($e)])."\n";exit(2);
}

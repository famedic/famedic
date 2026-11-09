<?php
namespace Famedic\Phase2C1;
trait RecordsResponses
{
    protected function record(string $id,string $method,string $path,array $input,$response): void
    {
        $file=getenv('FAMEDIC_PHASE2C1_OBSERVATIONS');
        if (!$file || !getenv('FAMEDIC_V2_EPHEMERAL')) {throw new \RuntimeException('Missing isolated recorder');}
        $data=$response->json();$redacted=[];
        $walk=function (&$value,$prefix='') use (&$walk,&$redacted): void {
            if (!is_array($value)) {return;}
            foreach ($value as $key=>&$item) {
                if (in_array($key,['token','access_token','account_context_token'],true)) {
                    $item='SYNTHETIC_PAT_REDACTED';$redacted[]=$prefix.$key;
                } else {$walk($item,$prefix.$key.'.');}
            }
        };
        $walk($data);
        file_put_contents($file,json_encode(['id'=>$id,'provenance'=>'OBSERVED_ISOLATED_HTTP',
            'method'=>$method,'path'=>$path,'synthetic_request'=>$input,'http'=>$response->getStatusCode(),
            'response'=>$data,'redacted_fields'=>$redacted],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
}

<?php
declare(strict_types=1);
use App\Domain\Akubica\Benefits\BenefitStateStore;
/** SYNTHETIC TEST STORAGE ONLY: no production credentials or data. */
final class FixtureBenefitStore implements BenefitStateStore
{
    private PDO $pdo;
    public bool $failReceipt=false;
    public bool $failDebit=false;
    public function __construct(string $file=':memory:')
    {
        if($file!==':memory:' && !str_starts_with($file,sys_get_temp_dir().'/famedic-benefits-fixture-'))throw new RuntimeException('Unsafe fixture path');
        $this->pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout=10000');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS fixture_state (bucket TEXT NOT NULL, identity TEXT NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(bucket,identity))');
    }
    private function table(string $table):void {if(!in_array($table,['carts','sources','options','confirmations','applications','receipts','debits'],true))throw new RuntimeException('Invalid fixture bucket');}
    public function transaction(callable $operation):mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try{$result=$operation();$this->pdo->exec('COMMIT');return $result;}catch(Throwable $e){$this->pdo->exec('ROLLBACK');throw $e;}
    }
    public function get(string $table,string $key):?array
    {
        $this->table($table);$q=$this->pdo->prepare('SELECT payload FROM fixture_state WHERE bucket=? AND identity=?');$q->execute([$table,$key]);$v=$q->fetchColumn();return $v===false?null:json_decode($v,true,512,JSON_THROW_ON_ERROR);
    }
    public function put(string $table,string $key,array $value):void
    {
        $this->table($table);if($this->failDebit && $table==='debits')throw new RuntimeException('Synthetic ledger failure');if($this->failReceipt && $table==='receipts')throw new RuntimeException('Synthetic storage failure');
        $q=$this->pdo->prepare('INSERT INTO fixture_state VALUES(?,?,?) ON CONFLICT(bucket,identity) DO UPDATE SET payload=excluded.payload');$q->execute([$table,$key,json_encode($value,JSON_THROW_ON_ERROR)]);
    }
    public function all(string $table):array
    {
        $this->table($table);$q=$this->pdo->prepare('SELECT identity,payload FROM fixture_state WHERE bucket=? ORDER BY identity');$q->execute([$table]);$result=[];foreach($q as $r)$result[$r['identity']]=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);return $result;
    }
}

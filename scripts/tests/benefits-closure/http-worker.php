<?php

// Only launched inside the isolated, network-disabled synthetic MySQL harness.
if (getenv('APP_ENV') !== 'testing' || getenv('FAMEDIC_V2_PREPARE_SCHEMA') !== '0') {
    throw new RuntimeException('Unsafe worker');
}
require getenv('APP_BASE_PATH').'/autoload-candidate.php';
$app = require getenv('APP_BASE_PATH').'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'benefits_synthetic_testing' || config('benefits.synthetic_mysql') !== true) {
    throw new RuntimeException('Unsafe worker database');
}
$app->instance(\App\Services\Api\V1\Benefits\BenefitApprovals::class, new class implements \App\Services\Api\V1\Benefits\BenefitApprovals
{
    public function api(): bool
    {
        return true;
    }

    public function consent(): bool
    {
        return true;
    }

    public function financial(): bool
    {
        return true;
    }

    public function textVersion(): ?string
    {
        return 'synthetic-consent-v1';
    }
});
$app->bind(\App\Contracts\Api\V1\Auth\BackendCredentialSource::class, \Tests\Support\Api\V1\Identity\LocalMachineCredentialSource::class);
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Bus::fake();
\Illuminate\Support\Facades\Queue::fake();
\Illuminate\Support\Facades\Notification::fake();
$d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($d['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($d['go'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }usleep(20000);
}

if(($d['mode']??'apply')==='reserve'){
 $actor=new \App\Domain\Akubica\Benefits\BenefitActor(...$d['actor']);
 try{$result=app(\App\Services\Api\V1\Benefits\MedicalCreditReservations::class)->reserve($actor,$d['cart_ref'],$d['application_ref'],$d['key']);echo json_encode(['status'=>200,'state'=>$result['state']]);}
 catch(\App\Domain\Akubica\Benefits\BenefitFailure $e){echo json_encode(['status'=>409,'code'=>$e->errorCode]);}exit;
}
if (($d['mode'] ?? 'apply') === 'finalize') {
    $app->instance(\App\Services\Api\V1\Benefits\MedicalPaymentAuthority::class, new class implements \App\Services\Api\V1\Benefits\MedicalPaymentAuthority
    {
        public function confirmed(\App\Models\Transaction $p, int $c, int $cash): bool
        {
            return true;
        }

        public function reversed(\App\Models\Transaction $p, int $c): bool
        {
            return true;
        }
    });
    $actor = new \App\Domain\Akubica\Benefits\BenefitActor(...$d['actor']);
    try {
        $result = app(\App\Services\Api\V1\Benefits\MedicalCreditSettlementBridge::class)->finalize($actor, $d['purchase_ref']);
        echo json_encode(['status' => 200, 'replayed' => $result['replayed']]);
    } catch (\App\Domain\Akubica\Benefits\BenefitFailure $e) {
        echo json_encode(['status' => 409, 'code' => $e->errorCode, 'replayed' => false]);
    }exit;
}
$req = \Illuminate\Http\Request::create('/api/v1/cart/benefits/applications', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $d['headers']['Authorization'], 'HTTP_X_SYNTHETIC_MACHINE_CREDENTIAL' => $d['headers']['X-Synthetic-Machine-Credential'], 'HTTP_IDEMPOTENCY_KEY' => $d['key']], json_encode($d['body']));
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($req);
$json = json_decode($response->getContent(), true);
echo json_encode(['status' => $response->getStatusCode(), 'code' => $json['error']['code'] ?? null, 'replayed' => $response->headers->get('Idempotency-Replayed') === 'true']);
$kernel->terminate($req,$response);

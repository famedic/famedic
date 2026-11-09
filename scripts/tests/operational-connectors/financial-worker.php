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

config()->set('famedic.medical_attention_subscription_price_cents', 1000);
config()->set('akubica_connectors.financial', ['enabled' => true, 'certified' => true, 'legacy_writers_coordinated' => true]);
\Illuminate\Support\Facades\Gate::define('purchaseMedicalBenefits', fn (\App\Models\User $u, \App\Domain\Akubica\Benefits\BenefitActor $a) => (int) $u->id === $a->userId);
$d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($d['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($d['go'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Synthetic barrier timeout');
    }usleep(20000);
}
$actor = new \App\Domain\Akubica\Benefits\BenefitActor(...$d['actor']);
try {
    $s = app(\App\Services\MedicalCheckout\MedicalPurchaseSaga::class);
    $r = $d['mode'] === 'start' ? $s->start($actor, $d['purchase_ref']) : $s->begin($actor, $d['cart_ref'], $d['application_ref'], $d['key'], 'paypal');
    echo json_encode(['status' => 200, 'state' => $r['state']]);
} catch (\App\Domain\Akubica\Benefits\BenefitFailure $e) {
    echo json_encode(['status' => 409, 'category' => $e->errorCode]);
}

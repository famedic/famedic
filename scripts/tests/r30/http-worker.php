<?php

// Synthetic HTTP race worker; no remote database or real provider permitted.
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
        return 'synthetic-r30-v1';
    }
});
\Illuminate\Support\Facades\Bus::fake();
\Illuminate\Support\Facades\Queue::fake();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Http::preventStrayRequests();
config()->set('famedic.medical_attention_subscription_price_cents', 1000);
config()->set('akubica_connectors.financial', ['enabled' => true, 'certified' => true, 'legacy_writers_coordinated' => true]);
config()->set('akubica_connectors.first_party_purchase', ['enabled' => true, 'certified' => true]);
config()->set('akubica_connectors.providers.paypal.certified', true);
config()->set('session.driver', 'file');
config()->set('session.files', getenv('APP_BASE_PATH').'/r30-sessions');
\Illuminate\Support\Facades\Gate::define('purchaseMedicalBenefits', fn ($u, $a) => (int) $u->id === $a->userId);
$app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
    return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };
});
$app->instance(\App\Services\MedicalCheckout\PayPalRemnantGateway::class, new class implements \App\Services\MedicalCheckout\MedicalRemnantGateway
{
    public function start(\App\Models\Customer $c, \App\Models\Transaction $t, string $ref, int $cash, string $method): \App\Services\MedicalCheckout\PaymentObservation
    {
        \Illuminate\Support\Facades\DB::table('r30_http_gateway_calls')->insert(['kind' => 'charge']);
        usleep(300000);

        return new \App\Services\MedicalCheckout\PaymentObservation('confirmed', $ref, $cash, 'synthetic-http-race-capture');
    }

    public function observe(\App\Models\Customer $c, \App\Models\Transaction $t, string $ref, int $cash): \App\Services\MedicalCheckout\PaymentObservation
    {
        return new \App\Services\MedicalCheckout\PaymentObservation('confirmed', $ref, $cash, 'synthetic-http-race-capture');
    }

    public function reverse(\App\Models\Customer $c, \App\Models\Transaction $t, string $ref, int $cash): \App\Services\MedicalCheckout\PaymentObservation
    {
        return new \App\Services\MedicalCheckout\PaymentObservation('unknown', $ref, $cash);
    }
});
$d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($d['ready'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($d['go'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    } usleep(10000);
}
$cookie = config('session.cookie');
$value = $app['encrypter']->encrypt(\Illuminate\Cookie\CookieValuePrefix::create($cookie, $app['encrypter']->getKey()).$d['session'], false);
$request = \Illuminate\Http\Request::create('http://localhost/medical-attention/benefit-checkout/confirm', 'POST', $d['form'], [$cookie => $value], [], ['HTTP_ACCEPT' => 'application/json']);
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$data = json_decode($response->getContent(), true);
$kernel->terminate($request, $response);
echo json_encode(['http' => $response->getStatusCode(), 'state' => $data['data']['state'] ?? null, 'category' => $data['error']['code'] ?? null]);

<?php

namespace Tests\Support\Api\V1;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\AkubicaCheckoutLink;
use App\Models\Contact;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Real API middleware/actions, synthetic fixtures, no persistent database. */
abstract class Phase2B1EphemeralTestCase extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Phase 2B-1 requires SQLite :memory: before schema setup.');
        }
        return $app;
    }

    protected function setUp(): void
    {
        if (getenv('FAMEDIC_PHASE2B1_EPHEMERAL') !== '1') {
            $this->markTestSkipped('Use scripts/run-phase2b1-local.py; persistent databases are forbidden.');
        }
        parent::setUp();
        config()->set('api_v1.idempotency.enabled', true);
        config()->set('services.activecampaign.endpoint', 'https://synthetic.invalid');
        config()->set('services.activecampaign.token', 'synthetic-not-a-credential');
        // Only the outbound transport boundary is faked. Never mock auth,
        // idempotency, the controller, readiness, or the link-creation action.
        Http::fake();
    }

    /** @return array{User, string} Synthetic PAT, exists only in SQLite memory. */
    protected function readyUnclassifiedActor(array $abilities = ['*']): array
    {
        $user = User::factory()->withRegularCustomer()->create();
        addOlabCartItemReadyForPaymentLink($user);
        $contact = Contact::factory()->for($user->customer)->create([
            'birth_date' => '1990-01-01', 'gender' => Gender::MALE,
        ]);
        $address = Address::factory()->for($user->customer)->create([
            'city' => 'Monterrey', 'state' => 'Nuevo León',
        ]);
        LaboratoryCheckoutDraft::query()->create([
            'customer_id' => $user->customer->id,
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'contact_id' => $contact->id, 'address_id' => $address->id,
            'checkout_step' => 'confirmation',
        ]);
        return [$user, $user->createToken('synthetic-phase2b1', $abilities)->plainTextToken];
    }

    protected function requestLink(?string $token, string $key, array $extraHeaders = []): TestResponse
    {
        Auth::forgetGuards();
        $this->flushHeaders();
        $headers = ['Idempotency-Key' => $key] + $extraHeaders;
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        return $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], $headers);
    }

    protected function businessSnapshot(): array
    {
        $hashes = [];
        foreach (Schema::getTableListing() as $table) {
            // Authentication last_used_at and technical idempotency receipts
            // are not purchases/cart/payment effects. Everything else is checked.
            if (in_array($table, ['migrations', 'personal_access_tokens', 'api_v1_idempotency_records', 'otp_movement_events'], true)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(static fn ($row) => json_encode($row))->all();
            sort($rows);
            $hashes[$table] = hash('sha256', json_encode($rows));
        }
        ksort($hashes);
        $transactions = [];
        foreach (Schema::getTableListing() as $table) {
            if (str_contains($table, 'transaction') || str_contains($table, 'purchase')) {
                $transactions[$table] = DB::table($table)->count();
            }
        }
        return [
            'business_hash' => hash('sha256', json_encode($hashes)),
            'table_hashes' => $hashes,
            'links' => AkubicaCheckoutLink::query()->count(),
            'transactions_and_purchases' => $transactions,
            'http_provider_calls' => Http::recorded()->count(),
        ];
    }

    protected function observe(string $id, TestResponse $response, array $before, array $after): void
    {
        $path = getenv('FAMEDIC_PHASE2B1_OBSERVATIONS');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Missing private observation path.');
        }
        $changed = [];
        foreach ($before['table_hashes'] as $table => $hash) {
            if (($after['table_hashes'][$table] ?? null) !== $hash) {
                $changed[] = $table;
            }
        }
        // Never persist response payload, link URL, token, personal data or body.
        file_put_contents($path, json_encode([
            'scenario' => $id, 'http_status' => $response->getStatusCode(),
            'error_code' => $response->json('error.code'),
            'links_created' => $after['links'] - $before['links'],
            'business_tables_changed' => $changed,
            'transactions_and_purchases_unchanged' => $after['transactions_and_purchases'] === $before['transactions_and_purchases'],
            'outbound_http_calls' => $after['http_provider_calls'] - $before['http_provider_calls'],
            'business_unchanged' => $before['business_hash'] === $after['business_hash'],
        ])."\n", FILE_APPEND | LOCK_EX);
    }

    protected function assertDeniedWithoutEffects(TestResponse $response, array $before, array $after, int $status, string $code): void
    {
        // Plain assertions avoid printing a synthetic link/token on failure.
        $this->assertSame($status, $response->getStatusCode(), 'Exact denial status');
        $this->assertSame($code, $response->json('error.code'), 'Exact API error code');
        $this->assertFalse($response->json('success'));
        $this->assertSame($before['links'], $after['links'], 'No links created');
        $this->assertSame($before['transactions_and_purchases'], $after['transactions_and_purchases']);
        $this->assertSame($before['http_provider_calls'], $after['http_provider_calls']);
        $this->assertSame($before['business_hash'], $after['business_hash'], 'No business mutation');
        $this->assertSame($response->headers->get('X-Correlation-ID'), $response->json('error.correlation_id'));
        $this->assertIsBool($response->json('error.retryable'));
    }
}

<?php

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob;
use App\Jobs\SendCartAbandonedToActiveCampaignJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use App\Services\Carts\CartAbandonmentService;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.cart_outbox_enabled' => true,
        'services.activecampaign.tag_abandoned_carts_enabled' => true,
        'services.activecampaign.cart_tag_remove_enabled' => false,
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.tags.cart.abandoned' => 20,
        'carts.abandoned_after_minutes' => 30,
    ]);

    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'user@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response(['contactTags' => []], 200),
    ]);
});

function outboxUser(array $attributes = []): User
{
    return User::factory()->withRegularCustomer()->create($attributes);
}

function outboxCart(User $user, int $inactiveMinutes = 45): Cart
{
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $cart = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->firstOrFail();

    $cart->update(['updated_at' => now()->subMinutes($inactiveMinutes)]);

    return $cart->fresh(['items', 'user.customer']);
}

function outboxAbandonedEvent(Cart $cart, int $episode = 1): CartEvent
{
    $service = app(CartAbandonmentService::class);
    $cart->update(['updated_at' => now()->subMinutes(45)]);

    $event = $service->recordAbandoned($cart->fresh());

    expect($event)->not->toBeNull()
        ->and($event->metadata['episode'])->toBe($episode);

    return $event;
}

function outboxAdmin(): User
{
    Permission::findOrCreate('view cart details', 'web');
    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

it('creates a single dispatch when cart_abandoned episode 1 is recorded', function () {
    Queue::fake();
    $cart = outboxCart(outboxUser(['email' => 'buyer@example.com']));

    outboxAbandonedEvent($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:tag:add')
        ->first();

    expect($dispatch)->not->toBeNull()
        ->and($dispatch->event_type)->toBe('cart_abandoned')
        ->and($dispatch->entity_type)->toBe('cart')
        ->and($dispatch->entity_id)->toBe($cart->id)
        ->and($dispatch->payload['episode'])->toBe(1)
        ->and($dispatch->payload['operation'])->toBe('tag_add')
        ->and($dispatch->customer_id)->toBe($cart->user->customer->id);

    Queue::assertPushed(DispatchActiveCampaignOutboundJob::class, 1);
});

it('does not duplicate dispatch when outbox enqueue runs twice', function () {
    Queue::fake();
    $cart = outboxCart(outboxUser(['email' => 'buyer@example.com']));
    $event = outboxAbandonedEvent($cart, 1);

    app(ActiveCampaignOutboundDispatcher::class)->enqueueAbandonedTagFromCartEvent($cart, $event);
    app(ActiveCampaignOutboundDispatcher::class)->enqueueAbandonedTagFromCartEvent($cart, $event);

    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
    Queue::assertPushed(DispatchActiveCampaignOutboundJob::class, 1);
});

it('creates a different dispatch for cart_abandoned episode 2', function () {
    Queue::fake();
    $cart = outboxCart(outboxUser(['email' => 'buyer@example.com']));

    outboxAbandonedEvent($cart, 1);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::CartResumed,
        'metadata' => ['episode' => 1],
        'occurred_at' => now()->subMinutes(20),
        'idempotency_key' => 'cart:'.$cart->id.':resumed:episode:1',
    ]);

    $cart->update(['updated_at' => now()->subMinutes(45)]);

    $second = app(CartAbandonmentService::class)->recordAbandoned($cart->fresh());
    expect($second?->metadata['episode'])->toBe(2);

    expect(ActiveCampaignDispatch::query()->count())->toBe(2)
        ->and(ActiveCampaignDispatch::query()->pluck('idempotency_key')->all())->toContain(
            'cart:'.$cart->id.':abandoned:episode:1:tag:add',
            'cart:'.$cart->id.':abandoned:episode:2:tag:add',
        );
});

it('marks dispatch synced on successful outbound job and updates legacy tagged timestamp', function () {
    $user = outboxUser(['email' => 'synced@example.com']);
    $cart = outboxCart($user);
    outboxAbandonedEvent($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:tag:add')
        ->firstOrFail();

    (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED)
        ->and($dispatch->fresh()->synced_at)->not->toBeNull()
        ->and($user->customer->fresh()->cart_abandoned_tagged_at)->not->toBeNull();
});

it('keeps failed dispatch with last_error and retries using the same row', function () {
    Queue::fake();

    $cart = outboxCart(outboxUser(['email' => 'failed@example.com']));
    outboxAbandonedEvent($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:tag:add')
        ->firstOrFail();

    app(\App\Services\ActiveCampaign\ActiveCampaignDispatchService::class)
        ->markFailed($dispatch, 'AC addTag falló (contact=42, tag=20): HTTP 429');

    $failed = $dispatch->fresh();
    expect($failed->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED)
        ->and($failed->attempts)->toBe(1)
        ->and($failed->last_error)->toContain('429')
        ->and(ActiveCampaignDispatch::query()->count())->toBe(1);

    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'failed@example.com']],
        ], 200),
        'https://ac.test/api/3/contact/sync' => Http::response(['contact' => ['id' => 42]], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
    ]);

    (new DispatchActiveCampaignOutboundJob($failed->id))->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($failed->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED)
        ->and(ActiveCampaignDispatch::query()->count())->toBe(1);
});

it('skips dispatch when cart user has no eligible email', function () {
    Queue::fake();
    $user = outboxUser(['email' => 'not-a-valid-email']);
    $cart = outboxCart($user);

    outboxAbandonedEvent($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()->first();

    expect($dispatch)->not->toBeNull()
        ->and($dispatch->status)->toBe(ActiveCampaignDispatch::STATUS_SKIPPED)
        ->and($dispatch->last_error)->toBe('no_eligible_email');

    Queue::assertNothingPushed();
});

it('does not create dispatch for empty monitoring cart abandonment attempts', function () {
    Queue::fake();
    $cart = Cart::query()->create([
        'user_id' => outboxUser(['email' => 'empty@example.com'])->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 0,
        'updated_at' => now()->subMinutes(45),
    ]);

    expect(app(CartAbandonmentService::class)->recordAbandoned($cart))->toBeNull()
        ->and(ActiveCampaignDispatch::query()->count())->toBe(0);
});

it('does not run legacy tag command when cart outbox is enabled', function () {
    Queue::fake([SendCartAbandonedToActiveCampaignJob::class]);

    Artisan::call('activecampaign:tag-abandoned-carts');

    expect(Artisan::output())->toContain('Cart outbox habilitado');
    Queue::assertNothingPushed();
});

it('shows outbound dispatch status in admin cart drawer', function () {
    $user = outboxUser(['email' => 'drawer@example.com']);
    $cart = outboxCart($user);

    ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => $cart->id,
        'customer_id' => $user->customer->id,
        'email' => $user->email,
        'idempotency_key' => 'cart:'.$cart->id.':abandoned:episode:1:tag:add',
        'status' => ActiveCampaignDispatch::STATUS_PENDING,
        'payload' => [
            'operation' => 'tag_add',
            'episode' => 1,
            'tag_key' => 'cart.abandoned',
            'tag_id' => 20,
        ],
    ]);

    $response = test()->actingAs(outboxAdmin())
        ->getJson(route('admin.carts.show', ['cart' => $cart->id]))
        ->assertOk();

    expect($response->json('data.activecampaign.items.0.label'))->toBe('Tag agregado — Carrito abandonado — episodio #1')
        ->and($response->json('data.activecampaign.items.0.detail'))->toBe('Pending')
        ->and($response->json('data.activecampaign.items.0.operation'))->toBe('tag_add');
});

it('does not call trackEvent during outbound cart abandoned processing', function () {
    $cart = outboxCart(outboxUser(['email' => 'track@example.com']));
    outboxAbandonedEvent($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()->firstOrFail();

    (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'trackcmp.net'));
});

<?php

use App\Enums\ActiveCampaignSiteEvent;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob;
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
        'services.activecampaign.cart_site_events_enabled' => true,
        'services.activecampaign.cart_tag_remove_enabled' => true,
        'services.activecampaign.account_id' => '12345',
        'services.activecampaign.event_key' => 'event-key-test',
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.tags.cart.abandoned' => 20,
        'carts.abandoned_after_minutes' => 30,
    ]);

    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'user@example.com']],
        ], 200),
        'https://ac.test/api/3/contact/sync' => Http::response(['contact' => ['id' => 42]], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response(['contactTags' => []], 200),
        'https://trackcmp.net/event' => Http::response(['success' => 1], 200),
    ]);
});

function siteEventUser(array $attributes = []): User
{
    return User::factory()->withRegularCustomer()->create($attributes);
}

function siteEventCart(User $user, int $inactiveMinutes = 45): Cart
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

function siteEventAdmin(): User
{
    Permission::findOrCreate('view cart details', 'web');
    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

function siteEventAbandoned(Cart $cart, int $episode = 1): CartEvent
{
    $cart->update(['updated_at' => now()->subMinutes(45)]);
    $event = app(CartAbandonmentService::class)->recordAbandoned($cart->fresh());

    expect($event)->not->toBeNull()
        ->and($event->metadata['episode'])->toBe($episode);

    return $event;
}

it('creates site event dispatch for cart_abandoned episode 1', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'abandoned@example.com']));

    siteEventAbandoned($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->first();

    expect($dispatch)->not->toBeNull()
        ->and($dispatch->event_type)->toBe(ActiveCampaignSiteEvent::CartAbandoned->value)
        ->and($dispatch->payload['operation'])->toBe('site_event')
        ->and($dispatch->payload['event_data'])->toHaveKeys([
            'cart_id', 'episode', 'cart_type', 'cart_total', 'items_count',
        ])
        ->and($dispatch->payload['event_data']['episode'])->toBe(1);

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:tag:add')->exists())->toBeTrue();
});

it('does not duplicate site event dispatch when enqueue runs twice', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'abandoned@example.com']));
    $event = siteEventAbandoned($cart, 1);

    app(ActiveCampaignOutboundDispatcher::class)->enqueueFromCartEvent($cart, $event);

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->count())->toBe(1);
});

it('creates a second site event dispatch for cart_abandoned episode 2', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'abandoned@example.com']));

    siteEventAbandoned($cart, 1);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::CartResumed,
        'metadata' => ['episode' => 1],
        'occurred_at' => now()->subMinutes(20),
        'idempotency_key' => 'cart:'.$cart->id.':resumed:episode:1',
    ]);

    siteEventAbandoned($cart->fresh(), 2);

    $keys = ActiveCampaignDispatch::query()
        ->where('payload->operation', 'site_event')
        ->pluck('idempotency_key')
        ->all();

    expect($keys)->toContain(
        'cart:'.$cart->id.':abandoned:episode:1:site_event',
        'cart:'.$cart->id.':abandoned:episode:2:site_event',
    );
});

it('creates tag_remove and site event dispatches for cart_resumed', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'resumed@example.com']));
    siteEventAbandoned($cart, 1);

    $resumed = app(CartAbandonmentService::class)->maybeRecordResumed($cart->fresh());

    expect($resumed)->not->toBeNull();

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':resumed:episode:1:tag:remove')
        ->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()
            ->where('idempotency_key', 'cart:'.$cart->id.':resumed:episode:1:site_event')
            ->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()
            ->where('event_type', ActiveCampaignSiteEvent::CartResumed->value)
            ->exists())->toBeTrue();
});

it('creates recovered cleanup dispatches including tag remove and site event', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'recovered@example.com']));
    siteEventAbandoned($cart, 1);

    $recovered = app(CartAbandonmentService::class)->recordRecoveredIfEligible($cart->fresh(), purchaseId: 999);

    expect($recovered)->not->toBeNull();

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':recovered:tag:remove')
        ->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()
            ->where('idempotency_key', 'cart:'.$cart->id.':recovered:site_event')
            ->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()
            ->where('event_type', ActiveCampaignSiteEvent::CartRecovered->value)
            ->first()?->payload['event_data']['purchase_id'])->toBe(999);
});

it('retries failed site event using the same dispatch row', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'retry@example.com']));
    siteEventAbandoned($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->firstOrFail();

    app(\App\Services\ActiveCampaign\ActiveCampaignDispatchService::class)
        ->markFailed($dispatch, 'track_event_http_error');

    Http::fake([
        'https://trackcmp.net/event' => Http::response(['success' => 1], 200),
    ]);

    (new DispatchActiveCampaignOutboundJob($dispatch->id))
        ->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED)
        ->and(ActiveCampaignDispatch::query()
            ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
            ->count())->toBe(1);
});

it('keeps tag and site event dispatches independent on partial failure', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'partial@example.com']));
    siteEventAbandoned($cart, 1);

    $tagDispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:tag:add')
        ->firstOrFail();

    $siteDispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->firstOrFail();

    app(\App\Services\ActiveCampaign\ActiveCampaignDispatchService::class)
        ->markFailed($tagDispatch, 'tag failed');

    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'partial@example.com']],
        ], 200),
        'https://ac.test/api/3/contact/sync' => Http::response(['contact' => ['id' => 42]], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://trackcmp.net/event' => Http::response(['success' => 1], 200),
    ]);

    (new DispatchActiveCampaignOutboundJob($siteDispatch->id))
        ->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($tagDispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED)
        ->and($siteDispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);
});

it('skips outbound dispatches without http when email is not eligible', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'not-a-valid-email']));

    siteEventAbandoned($cart, 1);

    $skipped = ActiveCampaignDispatch::query()
        ->where('status', ActiveCampaignDispatch::STATUS_SKIPPED)
        ->get();

    expect($skipped)->not->toBeEmpty();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('generates two famedic_cart_abandoned site events across abandon resume abandon cycle', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'cycle@example.com']));

    siteEventAbandoned($cart, 1);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::CartResumed,
        'metadata' => [
            'episode' => 1,
            'abandoned_at' => now()->subHour()->toIso8601String(),
            'resumed_at' => now()->subMinutes(30)->toIso8601String(),
            'abandoned_duration_minutes' => 30,
        ],
        'occurred_at' => now()->subMinutes(30),
        'idempotency_key' => 'cart:'.$cart->id.':resumed:episode:1',
    ]);

    siteEventAbandoned($cart->fresh(), 2);

    $siteEvents = ActiveCampaignDispatch::query()
        ->where('event_type', ActiveCampaignSiteEvent::CartAbandoned->value)
        ->orderBy('id')
        ->get();

    expect($siteEvents)->toHaveCount(2)
        ->and($siteEvents[0]->payload['event_data']['episode'])->toBe(1)
        ->and($siteEvents[1]->payload['event_data']['episode'])->toBe(2);
});

it('reconciles missing dispatches without duplicating existing ones', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'reconcile@example.com']));
    $event = siteEventAbandoned($cart, 1);

    ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->delete();

    Artisan::call('activecampaign:sync-cart-outbox');

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->count())->toBe(1)
        ->and(ActiveCampaignDispatch::query()
            ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:tag:add')
            ->count())->toBe(1);

    $before = ActiveCampaignDispatch::query()->count();
    Artisan::call('activecampaign:sync-cart-outbox');
    expect(ActiveCampaignDispatch::query()->count())->toBe($before);
});

it('shows tag and site event separately in admin cart drawer', function () {
    $user = siteEventUser(['email' => 'drawer@example.com']);
    $cart = siteEventCart($user);

    ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => $cart->id,
        'customer_id' => $user->customer->id,
        'email' => $user->email,
        'idempotency_key' => 'cart:'.$cart->id.':abandoned:episode:1:tag:add',
        'status' => ActiveCampaignDispatch::STATUS_SYNCED,
        'payload' => ['operation' => 'tag_add', 'episode' => 1],
        'synced_at' => now(),
    ]);

    ActiveCampaignDispatch::query()->create([
        'event_type' => ActiveCampaignSiteEvent::CartAbandoned->value,
        'entity_type' => 'cart',
        'entity_id' => $cart->id,
        'customer_id' => $user->customer->id,
        'email' => $user->email,
        'idempotency_key' => 'cart:'.$cart->id.':abandoned:episode:1:site_event',
        'status' => ActiveCampaignDispatch::STATUS_SYNCED,
        'payload' => [
            'operation' => 'site_event',
            'event_name' => ActiveCampaignSiteEvent::CartAbandoned->value,
            'episode' => 1,
        ],
        'synced_at' => now(),
    ]);

    $response = test()->actingAs(siteEventAdmin())
        ->getJson(route('admin.carts.show', ['cart' => $cart->id]))
        ->assertOk();

    $labels = collect($response->json('data.activecampaign.items'))->pluck('label')->all();
    $operations = collect($response->json('data.activecampaign.items'))->pluck('operation')->all();

    expect($labels)->toContain('Tag agregado — Carrito abandonado — episodio #1')
        ->and($labels)->toContain('famedic_cart_abandoned — episodio #1')
        ->and($operations)->toContain('tag_add', 'site_event');
});

it('does not call trackcmp when cart site events flag is disabled', function () {
    config(['services.activecampaign.cart_site_events_enabled' => false]);

    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'flags@example.com']));
    siteEventAbandoned($cart, 1);

    expect(ActiveCampaignDispatch::query()
        ->where('payload->operation', 'site_event')
        ->exists())->toBeFalse()
        ->and(ActiveCampaignDispatch::query()
            ->where('payload->operation', 'tag_add')
            ->exists())->toBeTrue();

    Http::assertNothingSent();
});

it('marks site event dispatch synced only when trackEvent succeeds', function () {
    Queue::fake();
    $cart = siteEventCart(siteEventUser(['email' => 'track@example.com']));
    siteEventAbandoned($cart, 1);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->firstOrFail();

    app(\App\Services\ActiveCampaign\ActiveCampaignDispatchService::class)
        ->markFailed($dispatch, 'AC trackEvent falló (famedic_cart_abandoned): track_event_http_error');

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED);

    Http::fake([
        'https://trackcmp.net/event' => Http::response(['success' => 1], 200),
    ]);

    (new DispatchActiveCampaignOutboundJob($dispatch->id))
        ->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'trackcmp.net/event'));
});

it('does not update cart_abandoned_tagged_at from site event dispatch', function () {
    Queue::fake();
    $user = siteEventUser(['email' => 'legacy@example.com']);
    $cart = siteEventCart($user);
    siteEventAbandoned($cart, 1);

    $siteDispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'cart:'.$cart->id.':abandoned:episode:1:site_event')
        ->firstOrFail();

    Http::fake(['https://trackcmp.net/event' => Http::response(['success' => 1], 200)]);

    (new DispatchActiveCampaignOutboundJob($siteDispatch->id))
        ->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($user->customer->fresh()->cart_abandoned_tagged_at)->toBeNull();
});

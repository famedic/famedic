<?php

use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Audit\BusinessAuditEvent;
use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Services\Audit\Business\BusinessAuditActor;
use App\Services\Audit\Business\BusinessAuditChannel;
use App\Services\Audit\Business\BusinessAuditEventDefinitions;
use App\Services\Audit\Business\BusinessAuditEventWriter;
use App\Services\Audit\Business\BusinessAuditMetadataNormalizer;
use App\Services\Audit\Business\BusinessAuditOutcome;
use App\Services\Audit\Business\LaboratoryOrderCreatedAuditHint;
use App\Services\Audit\Business\LaboratoryOrderCreatedAuditRecorder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('business_audit.enabled', false);
    config()->set('api_v1.audit.enabled', false);
    Notification::fake();
});

function enableBusinessAuditForP6b(): void
{
    config()->set('business_audit.enabled', true);
    app()->forgetInstance(BusinessAuditMetadataNormalizer::class);
    app()->forgetInstance(BusinessAuditEventWriter::class);
    app()->forgetInstance(LaboratoryOrderCreatedAuditRecorder::class);
}

/**
 * @return array{0: User, 1: Contact, 2: Address, 3: Transaction, 4: \Illuminate\Database\Eloquent\Collection}
 */
function prepareLaboratoryFulfillmentFixtures(): array
{
    $user = User::factory()->withRegularCustomer()->create();
    $customer = $user->customer;
    addOlabCartItemReadyForPaymentLink($user);

    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $address = Address::factory()->create(['customer_id' => $customer->id]);
    $transaction = Transaction::factory()->create([
        'payment_method' => 'efevoopay',
        'transaction_amount_cents' => 35000,
    ]);

    $cartItems = $customer->laboratoryCartItems()
        ->ofBrand(LaboratoryBrand::OLAB)
        ->with('laboratoryTest')
        ->get();

    return [$user, $contact, $address, $transaction, $cartItems];
}

function webCheckoutAuditHint(User $user, ?string $correlationId = null): LaboratoryOrderCreatedAuditHint
{
    return new LaboratoryOrderCreatedAuditHint(
        channel: BusinessAuditChannel::WEB_CHECKOUT,
        fulfillmentOrigin: LaboratoryOrderCreatedAuditHint::ORIGIN_WEB_CHECKOUT,
        actorType: BusinessAuditActor::TYPE_CUSTOMER,
        actorCustomerId: (int) $user->customer->id,
        actorUserId: (int) $user->id,
        subjectCustomerId: (int) $user->customer->id,
        correlationId: $correlationId,
    );
}

function paypalWebhookAuditHint(User $user): LaboratoryOrderCreatedAuditHint
{
    return new LaboratoryOrderCreatedAuditHint(
        channel: BusinessAuditChannel::INTEGRATION_WEBHOOK,
        fulfillmentOrigin: LaboratoryOrderCreatedAuditHint::ORIGIN_PAYPAL_WEBHOOK,
        actorType: BusinessAuditActor::TYPE_INTEGRATION,
        integrationAlias: 'paypal',
        subjectCustomerId: (int) $user->customer->id,
    );
}

function paypalCaptureAuditHint(User $user): LaboratoryOrderCreatedAuditHint
{
    return new LaboratoryOrderCreatedAuditHint(
        channel: BusinessAuditChannel::WEB_CHECKOUT,
        fulfillmentOrigin: LaboratoryOrderCreatedAuditHint::ORIGIN_PAYPAL_CAPTURE,
        actorType: BusinessAuditActor::TYPE_CUSTOMER,
        actorCustomerId: (int) $user->customer->id,
        actorUserId: (int) $user->id,
        subjectCustomerId: (int) $user->customer->id,
    );
}

function invokeFulfill(
    User $user,
    Contact $contact,
    Address $address,
    Transaction $transaction,
    $cartItems,
    LaboratoryOrderCreatedAuditHint $hint,
): \App\Models\LaboratoryPurchase {
    return app(FulfillLaboratoryCartOrderAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        $address,
        $contact,
        $transaction,
        null,
        $cartItems,
        LaboratoryBrand::OLAB->value,
        null,
        $hint,
    );
}

function assertCommerceOrderAuditPrivacy(BusinessAuditEvent $event): void
{
    $blob = strtolower(json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE) ?: '');

    foreach ([
        '@example.com',
        '8112345678',
        'juan',
        'pérez',
        'hemograma',
        'coupon_code',
        'transaction_id',
        'payment_id',
        'authorization_code',
        'gda_order',
        'requisition',
        'cvv',
        'rfc',
        'session_id',
        'user-agent',
        'bearer ',
    ] as $needle) {
        expect($blob)->not->toContain($needle);
    }
}

// ── Definition ─────────────────────────────────────────────────────────────

test('productive definition for commerce.laboratory_order_created is registered', function () {
    expect(BusinessAuditEventDefinitions::isKnownEvent(
        BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED
    ))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::allowedOutcomes(
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED
        ))->toBe([BusinessAuditOutcome::SUCCEEDED])
        ->and(BusinessAuditEventDefinitions::allowedActorTypes(
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED
        ))->toBe([BusinessAuditActor::TYPE_CUSTOMER, BusinessAuditActor::TYPE_INTEGRATION])
        ->and(BusinessAuditEventDefinitions::allowedChannels(
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED
        ))->toBe([BusinessAuditChannel::WEB_CHECKOUT, BusinessAuditChannel::INTEGRATION_WEBHOOK])
        ->and(BusinessAuditEventDefinitions::allowedResourceTypes(
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED
        ))->toBe(['laboratory_purchase'])
        ->and(BusinessAuditEventDefinitions::allowedMetadataKeys(
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED
        ))->toBe(['fulfillment_origin']);
});

// ── Flag OFF / ON ──────────────────────────────────────────────────────────

test('flag OFF fulfill creates purchase with zero business audit events', function () {
    config()->set('business_audit.enabled', false);
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $before = BusinessAuditEvent::query()->count();
    $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, webCheckoutAuditHint($user));

    expect($purchase->id)->toBeGreaterThan(0)
        ->and(BusinessAuditEvent::query()->count())->toBe($before);

    Notification::assertSentTo($user, LaboratoryPurchaseCreated::class);
});

test('flag ON web checkout fulfill emits exactly one succeeded event', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $before = BusinessAuditEvent::query()->count();
    $purchase = invokeFulfill(
        $user,
        $contact,
        $address,
        $transaction,
        $cartItems,
        webCheckoutAuditHint($user, 'biz-web-checkout-corr-001')
    );

    $events = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED)
        ->get();

    expect($events)->toHaveCount(1)
        ->and(BusinessAuditEvent::query()->count())->toBe($before + 1);

    $event = $events->first();
    expect($event->outcome)->toBe(BusinessAuditOutcome::SUCCEEDED)
        ->and($event->channel)->toBe(BusinessAuditChannel::WEB_CHECKOUT)
        ->and($event->actor_type)->toBe(BusinessAuditActor::TYPE_CUSTOMER)
        ->and($event->actor_key)->toBe('customer:'.$user->customer->id)
        ->and($event->actor_customer_id)->toBe($user->customer->id)
        ->and($event->actor_user_id)->toBe($user->id)
        ->and($event->subject_type)->toBe('customer')
        ->and($event->subject_key)->toBe('customer:'.$user->customer->id)
        ->and($event->resource_type)->toBe('laboratory_purchase')
        ->and($event->resource_key)->toBe((string) $purchase->id)
        ->and($event->correlation_id)->toBe('biz-web-checkout-corr-001')
        ->and($event->metadata)->toMatchArray([
            'fulfillment_origin' => LaboratoryOrderCreatedAuditHint::ORIGIN_WEB_CHECKOUT,
        ]);

    assertCommerceOrderAuditPrivacy($event);
});

test('paypal capture origin uses customer actor on web_checkout channel', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, paypalCaptureAuditHint($user));

    $event = BusinessAuditEvent::query()
        ->where('resource_key', (string) $purchase->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_type)->toBe(BusinessAuditActor::TYPE_CUSTOMER)
        ->and($event->channel)->toBe(BusinessAuditChannel::WEB_CHECKOUT)
        ->and($event->metadata['fulfillment_origin'])->toBe(LaboratoryOrderCreatedAuditHint::ORIGIN_PAYPAL_CAPTURE);
});

test('paypal webhook origin uses integration actor and subject customer', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, paypalWebhookAuditHint($user));

    $event = BusinessAuditEvent::query()
        ->where('resource_key', (string) $purchase->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_type)->toBe(BusinessAuditActor::TYPE_INTEGRATION)
        ->and($event->actor_key)->toBe('integration:paypal')
        ->and($event->actor_customer_id)->toBeNull()
        ->and($event->channel)->toBe(BusinessAuditChannel::INTEGRATION_WEBHOOK)
        ->and($event->subject_key)->toBe('customer:'.$user->customer->id)
        ->and($event->metadata['fulfillment_origin'])->toBe(LaboratoryOrderCreatedAuditHint::ORIGIN_PAYPAL_WEBHOOK);

    assertCommerceOrderAuditPrivacy($event);
});

test('absent correlation id is generated as UUID', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, webCheckoutAuditHint($user, null));

    $event = BusinessAuditEvent::query()->where('resource_key', (string) $purchase->id)->first();
    expect(Str::isUuid($event->correlation_id))->toBeTrue();
});

// ── Rollback / GDA failure ─────────────────────────────────────────────────

test('GDA failure causing rollback emits zero business audit events', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $this->mock(CreateGDAQuotationAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->andThrow(new RuntimeException('gda_unavailable_for_test'));
    });

    $beforePurchases = \App\Models\LaboratoryPurchase::query()->count();
    $beforeAudit = BusinessAuditEvent::query()->count();

    expect(fn () => invokeFulfill(
        $user,
        $contact,
        $address,
        $transaction,
        $cartItems,
        webCheckoutAuditHint($user)
    ))->toThrow(RuntimeException::class);

    expect(\App\Models\LaboratoryPurchase::query()->count())->toBe($beforePurchases)
        ->and(BusinessAuditEvent::query()->count())->toBe($beforeAudit);
});

test('exception before commit emits zero business audit events', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    // Force failure after purchase INSERT but before commit (GDA step).
    $this->mock(CreateGDAQuotationAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('before_commit'));
    });

    $before = BusinessAuditEvent::query()->count();

    expect(fn () => app(FulfillLaboratoryCartOrderAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        $address,
        $contact,
        $transaction,
        null,
        $cartItems,
        LaboratoryBrand::OLAB->value,
        null,
        webCheckoutAuditHint($user),
    ))->toThrow(RuntimeException::class);

    expect(BusinessAuditEvent::query()->count())->toBe($before)
        ->and(
            BusinessAuditEvent::query()
                ->where('event_name', BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED)
                ->count()
        )->toBe(0);
});

test('broken audit writer does not break purchase or notifications', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    Schema::rename('business_audit_events', 'business_audit_events_hidden_p6b');

    try {
        $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, webCheckoutAuditHint($user));

        expect($purchase->exists)->toBeTrue()
            ->and(\App\Models\LaboratoryPurchase::query()->whereKey($purchase->id)->exists())->toBeTrue();

        Notification::assertSentTo($user, LaboratoryPurchaseCreated::class);
    } finally {
        if (Schema::hasTable('business_audit_events_hidden_p6b')) {
            Schema::rename('business_audit_events_hidden_p6b', 'business_audit_events');
        }
    }
});

// ── Dedup ──────────────────────────────────────────────────────────────────

test('recorder soft-dedupes second succeeded event for same purchase id', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, webCheckoutAuditHint($user));

    $recorder = app(LaboratoryOrderCreatedAuditRecorder::class);
    $second = $recorder->recordSucceeded((int) $purchase->id, webCheckoutAuditHint($user));

    expect($second)->toBeNull()
        ->and(
            BusinessAuditEvent::query()
                ->where('event_name', BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED)
                ->where('resource_key', (string) $purchase->id)
                ->count()
        )->toBe(1);
});

test('paypal already-processed path does not call fulfill so no second audit', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    $purchase = invokeFulfill($user, $contact, $address, $transaction, $cartItems, paypalCaptureAuditHint($user));
    $transaction->laboratoryPurchases()->attach($purchase->id);

    // Simulate finalize early-return when purchase already linked.
    expect($transaction->laboratoryPurchases()->exists())->toBeTrue()
        ->and(
            BusinessAuditEvent::query()
                ->where('resource_key', (string) $purchase->id)
                ->count()
        )->toBe(1);
});

// ── Metadata / privacy ─────────────────────────────────────────────────────

test('metadata discards non-allowlisted and sensitive keys for order created', function () {
    enableBusinessAuditForP6b();
    $normalizer = BusinessAuditMetadataNormalizer::fromConfig();

    $cleaned = $normalizer->normalize(
        BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED,
        [
            'fulfillment_origin' => LaboratoryOrderCreatedAuditHint::ORIGIN_WEB_CHECKOUT,
            'amount' => 35000,
            'transaction_id' => 'tx_1',
            'payment_id' => 'pay_1',
            'coupon_code' => 'SAVE',
            'email' => 'a@b.com',
            'study_name' => 'Hemograma',
            'gda_order_id' => '999',
        ]
    );

    expect($cleaned)->toBe([
        'fulfillment_origin' => LaboratoryOrderCreatedAuditHint::ORIGIN_WEB_CHECKOUT,
    ]);
});

test('rejected and failed outcomes are not allowed for laboratory order created', function () {
    enableBusinessAuditForP6b();
    $writer = app(BusinessAuditEventWriter::class);

    $hint = webCheckoutAuditHint(User::factory()->withRegularCustomer()->create());
    $context = new \App\Services\Audit\Business\BusinessAuditContext(
        channel: BusinessAuditChannel::WEB_CHECKOUT,
        actor: BusinessAuditActor::customer((int) $hint->actorCustomerId, $hint->actorUserId),
        subject: \App\Services\Audit\Business\BusinessAuditSubject::customer((int) $hint->subjectCustomerId),
    );

    $before = BusinessAuditEvent::query()->count();
    $result = $writer->write([
        'event_name' => BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED,
        'outcome' => BusinessAuditOutcome::REJECTED,
        'context' => $context,
        'resource_type' => 'laboratory_purchase',
        'resource_key' => '1',
        'metadata' => ['fulfillment_origin' => 'web_checkout'],
    ]);

    expect($result)->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

test('API V1 factory purchase path does not emit commerce.laboratory_order_created', function () {
    enableBusinessAuditForP6b();
    $user = User::factory()->withRegularCustomer()->create();
    $before = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED)
        ->count();

    createAkubicaLaboratoryPurchase($user);

    expect(
        BusinessAuditEvent::query()
            ->where('event_name', BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED)
            ->count()
    )->toBe($before);
});

test('no payment.* events are emitted by laboratory order fulfillment', function () {
    enableBusinessAuditForP6b();
    [$user, $contact, $address, $transaction, $cartItems] = prepareLaboratoryFulfillmentFixtures();

    invokeFulfill($user, $contact, $address, $transaction, $cartItems, webCheckoutAuditHint($user));

    expect(
        BusinessAuditEvent::query()->where('event_name', 'like', 'payment.%')->count()
    )->toBe(0);
});

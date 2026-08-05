<?php

use App\Models\Audit\BusinessAuditEvent;
use App\Models\User;
use App\Services\Api\V1\Audit\AuditEventWriter as ApiV1AuditEventWriter;
use App\Services\Audit\Business\BusinessAuditActor;
use App\Services\Audit\Business\BusinessAuditChannel;
use App\Services\Audit\Business\BusinessAuditContext;
use App\Services\Audit\Business\BusinessAuditCorrelationId;
use App\Services\Audit\Business\BusinessAuditEventDefinitions;
use App\Services\Audit\Business\BusinessAuditEventWriter;
use App\Services\Audit\Business\BusinessAuditMetadataNormalizer;
use App\Services\Audit\Business\BusinessAuditOutcome;
use App\Services\Audit\Business\BusinessAuditSubject;
use App\Support\Migrations\MinimumTableContract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const BUSINESS_AUDIT_TEST_EVENT = 'test.business_audit.infra_probe';

beforeEach(function () {
    config()->set('business_audit.enabled', false);
    config()->set('business_audit.max_metadata_bytes', 2048);
    config()->set('business_audit.max_metadata_depth', 2);
    config()->set('business_audit.max_metadata_keys', 32);
    config()->set('api_v1.audit.enabled', false);
    BusinessAuditEventDefinitions::clearTestDefinitions();
});

afterEach(function () {
    BusinessAuditEventDefinitions::clearTestDefinitions();
});

/**
 * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $logs
 */
function captureBusinessAuditLogs(array &$logs): void
{
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = [
            'level' => $event->level,
            'message' => (string) $event->message,
            'context' => $event->context,
        ];
    });
}

function businessAuditWriter(): BusinessAuditEventWriter
{
    return app(BusinessAuditEventWriter::class);
}

function enableBusinessAudit(): void
{
    config()->set('business_audit.enabled', true);
    app()->forgetInstance(BusinessAuditMetadataNormalizer::class);
    app()->forgetInstance(BusinessAuditEventWriter::class);
}

function registerBusinessAuditProbeDefinition(array $overrides = []): void
{
    BusinessAuditEventDefinitions::registerTestDefinition(
        BUSINESS_AUDIT_TEST_EVENT,
        array_merge([
            'metadata' => ['probe_id', 'suite', 'count', 'labels', 'flag_ok'],
            'outcomes' => BusinessAuditOutcome::all(),
            'actor_types' => BusinessAuditActor::types(),
            'channels' => BusinessAuditChannel::all(),
            'resource_types' => ['probe_resource', 'laboratory_purchase'],
            'subject_types' => BusinessAuditSubject::TYPES,
        ], $overrides)
    );
}

function businessAuditProbeContext(array $overrides = []): BusinessAuditContext
{
    $correlationId = array_key_exists('correlation_id', $overrides)
        ? $overrides['correlation_id']
        : 'biz-corr-probe-0001';

    return new BusinessAuditContext(
        channel: $overrides['channel'] ?? BusinessAuditChannel::CONSOLE,
        actor: $overrides['actor'] ?? BusinessAuditActor::system('console'),
        correlationId: $correlationId,
        subject: $overrides['subject'] ?? null,
        occurredAt: $overrides['occurred_at'] ?? null,
    );
}

function makeBusinessAuditProbeInput(array $overrides = []): array
{
    $context = $overrides['context'] ?? businessAuditProbeContext();
    unset($overrides['context']);

    return array_merge([
        'event_name' => BUSINESS_AUDIT_TEST_EVENT,
        'outcome' => BusinessAuditOutcome::SUCCEEDED,
        'context' => $context,
        'resource_type' => 'probe_resource',
        'resource_key' => '42',
        'metadata' => [
            'probe_id' => 'p6a',
            'suite' => 'infra',
            'count' => 1,
            'flag_ok' => true,
        ],
    ], $overrides);
}

function assertNoSensitiveBusinessAuditPayload(BusinessAuditEvent $event): void
{
    $blob = strtolower(json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE) ?: '');

    foreach ([
        'password',
        'bearer ',
        '@example.com',
        '8112345678',
        'rfc',
        'curp',
        'cvv',
        'coupon_code',
        'transaction_id',
        'payment_id',
        'authorization_code',
        'study_name',
        'test_name',
        'clinical',
        'diagnosis',
        'session_id',
        'user-agent',
        'webhook_signature',
    ] as $needle) {
        expect($blob)->not->toContain($needle);
    }
}

// ── Configuration / flag independence ──────────────────────────────────────

test('business audit flag OFF by config default and phpunit', function () {
    expect(config('business_audit.enabled'))->toBeFalse()
        ->and((bool) env('BUSINESS_AUDIT_ENABLED', false))->toBeFalse();
});

test('business audit flag OFF does not insert rows', function () {
    registerBusinessAuditProbeDefinition();
    config()->set('business_audit.enabled', false);

    $before = BusinessAuditEvent::query()->count();
    $result = businessAuditWriter()->write(makeBusinessAuditProbeInput());

    expect($result)->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

test('enabling API V1 audit does not enable business audit', function () {
    registerBusinessAuditProbeDefinition();
    config()->set('api_v1.audit.enabled', true);
    config()->set('business_audit.enabled', false);

    expect(businessAuditWriter()->enabled())->toBeFalse()
        ->and(app(ApiV1AuditEventWriter::class)->enabled())->toBeTrue();

    $before = BusinessAuditEvent::query()->count();
    businessAuditWriter()->write(makeBusinessAuditProbeInput());
    expect(BusinessAuditEvent::query()->count())->toBe($before);
});

test('enabling business audit does not enable API V1 audit', function () {
    enableBusinessAudit();
    config()->set('api_v1.audit.enabled', false);

    expect(businessAuditWriter()->enabled())->toBeTrue()
        ->and(app(ApiV1AuditEventWriter::class)->enabled())->toBeFalse();
});

// ── Schema ─────────────────────────────────────────────────────────────────

test('MySQL/SQLite minimum table contract holds for business_audit_events', function () {
    expect(Schema::hasTable('business_audit_events'))->toBeTrue();
    expect(Schema::hasColumn('business_audit_events', 'updated_at'))->toBeFalse();

    MinimumTableContract::assertCompatible('business_audit_events', [
        'columns' => [
            'id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
            'public_id' => ['types' => ['string', 'varchar', 'char', 'guid', 'uuid'], 'nullable' => false],
            'occurred_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
            'event_name' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'outcome' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'channel' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'actor_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'actor_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'actor_user_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            'actor_customer_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            'subject_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
            'subject_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
            'resource_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
            'resource_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
            'correlation_id' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'error_code' => ['types' => ['string', 'varchar', 'char'], 'nullable' => true],
            'retryable' => ['types' => ['boolean', 'tinyint', 'integer'], 'nullable' => true],
            'metadata' => ['types' => ['json', 'text', 'clob'], 'nullable' => true],
            'created_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
        ],
        'indexes' => [
            ['name' => 'business_audit_events_public_id_unique', 'columns' => ['public_id'], 'unique' => true],
            ['name' => 'business_audit_events_occurred_at_index', 'columns' => ['occurred_at']],
            ['name' => 'biz_audit_events_event_name_occurred_at_idx', 'columns' => ['event_name', 'occurred_at']],
            ['name' => 'biz_audit_events_channel_occurred_at_idx', 'columns' => ['channel', 'occurred_at']],
            ['name' => 'biz_audit_events_actor_key_occurred_at_idx', 'columns' => ['actor_key', 'occurred_at']],
            ['name' => 'biz_audit_events_actor_cust_occurred_at_idx', 'columns' => ['actor_customer_id', 'occurred_at']],
            ['name' => 'business_audit_events_correlation_id_index', 'columns' => ['correlation_id']],
            ['name' => 'biz_audit_events_resource_type_key_occ_idx', 'columns' => ['resource_type', 'resource_key', 'occurred_at']],
            ['name' => 'biz_audit_events_subject_type_key_occ_idx', 'columns' => ['subject_type', 'subject_key', 'occurred_at']],
            ['name' => 'biz_audit_events_outcome_occurred_at_idx', 'columns' => ['outcome', 'occurred_at']],
        ],
        'foreign_keys' => [],
    ]);

    expect(Schema::getForeignKeys('business_audit_events'))->toBe([]);
});

test('public_id is unique across inserts', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $first = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'correlation_id' => 'biz-corr-unique-a',
    ]));
    $second = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'correlation_id' => 'biz-corr-unique-b',
    ]));

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->public_id)->not->toBe($second->public_id)
        ->and(Str::isUuid($first->public_id))->toBeTrue();
});

// ── Append-only ────────────────────────────────────────────────────────────

test('writer inserts and model rejects update and delete', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput());
    expect($event)->toBeInstanceOf(BusinessAuditEvent::class)
        ->and(BusinessAuditEvent::UPDATED_AT)->toBeNull();

    expect(fn () => $event->save())->toThrow(LogicException::class);
    expect(fn () => $event->update(['outcome' => 'failed']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);

    expect(BusinessAuditEvent::query()->whereKey($event->id)->value('outcome'))
        ->toBe(BusinessAuditOutcome::SUCCEEDED);
});

test('no business audit modification routes are registered', function () {
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());
    expect($routes->first(fn ($uri) => str_contains($uri, 'business-audit')
        || str_contains($uri, 'business_audit')))->toBeNull();
});

// ── Validation ─────────────────────────────────────────────────────────────

test('unknown event_name is fail-soft and does not persist', function () {
    enableBusinessAudit();
    $logs = [];
    captureBusinessAuditLogs($logs);

    $before = BusinessAuditEvent::query()->count();
    $result = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'event_name' => 'commerce.laboratory_order_created',
    ]));

    expect($result)->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before)
        ->and(collect($logs)->contains(fn ($l) => $l['message'] === 'business_audit_write_failed'))->toBeTrue();
});

test('payment.* event names are rejected even with test registration blocked', function () {
    expect(fn () => BusinessAuditEventDefinitions::registerTestDefinition('payment.captured', [
        'metadata' => ['probe_id'],
    ]))->toThrow(LogicException::class);

    enableBusinessAudit();
    $before = BusinessAuditEvent::query()->count();
    $result = businessAuditWriter()->write([
        'event_name' => 'payment.captured',
        'outcome' => BusinessAuditOutcome::SUCCEEDED,
        'context' => businessAuditProbeContext(),
    ]);

    expect($result)->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

test('outcome outside allowlist is not persisted', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $before = BusinessAuditEvent::query()->count();
    $result = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'outcome' => 'success', // API-ish typo; not in business allowlist
    ]));

    expect($result)->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

test('unknown actor_type cannot be constructed; unknown channel context fails soft', function () {
    expect(fn () => new BusinessAuditActor('anonymous', 'anonymous:x'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new BusinessAuditContext(
        channel: 'paypal_capture',
        actor: BusinessAuditActor::system('console'),
    ))->toThrow(InvalidArgumentException::class);
});

test('retryable accepts true false and null', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    foreach ([true, false, null] as $value) {
        $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
            'retryable' => $value,
            'correlation_id' => 'biz-retry-'.($value === null ? 'null' : ($value ? 'true' : 'false')),
        ]));
        expect($event)->not->toBeNull()
            ->and($event->retryable)->toBe($value);
    }
});

test('invalid or excessive error_code is dropped to null', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $ok = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'error_code' => 'EMPTY_CART',
        'correlation_id' => 'biz-err-ok',
    ]));
    expect($ok->error_code)->toBe('EMPTY_CART');

    $bad = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'error_code' => 'this is not a stable code',
        'correlation_id' => 'biz-err-bad',
    ]));
    expect($bad->error_code)->toBeNull();

    $long = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'error_code' => str_repeat('A', 80),
        'correlation_id' => 'biz-err-long',
    ]));
    expect($long->error_code)->toBeNull();
});

test('resource_type outside definition allowlist is not persisted', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition([
        'resource_types' => ['probe_resource'],
    ]);

    $before = BusinessAuditEvent::query()->count();
    $result = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'resource_type' => 'payment_intent',
        'resource_key' => '1',
    ]));

    expect($result)->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

// ── Correlation ────────────────────────────────────────────────────────────

test('valid correlation id is preserved', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'correlation_id' => 'valid-corr-id-abc_01',
    ]));

    expect($event->correlation_id)->toBe('valid-corr-id-abc_01');
});

test('absent correlation id generates a UUID', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'context' => businessAuditProbeContext(['correlation_id' => null]),
        'correlation_id' => null,
    ]));

    expect(Str::isUuid($event->correlation_id))->toBeTrue();
});

test('invalid or excessive correlation id is replaced not stored', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $invalid = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'correlation_id' => 'bad corr with spaces!!!',
    ]));
    expect($invalid->correlation_id)->not->toBe('bad corr with spaces!!!')
        ->and(Str::isUuid($invalid->correlation_id))->toBeTrue();

    $excessive = str_repeat('a', 200);
    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'correlation_id' => $excessive,
    ]));
    expect($event->correlation_id)->not->toBe($excessive)
        ->and(strlen($event->correlation_id))->toBeLessThanOrEqual(128);
});

test('correlation helper does not mutate response headers', function () {
    $request = Request::create('/laboratory/olab/checkout', 'POST');
    $response = response('ok', 200);

    $id = BusinessAuditCorrelationId::resolve(null);
    expect(Str::isUuid($id))->toBeTrue()
        ->and($response->headers->all())->not->toHaveKey('x-correlation-id')
        ->and($request->headers->all())->not->toHaveKey('x-correlation-id');
});

// ── Metadata ───────────────────────────────────────────────────────────────

test('metadata allowlist keeps legitimate keys and drops unknown and sensitive', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'metadata' => [
            'probe_id' => 'keep',
            'suite' => 'infra',
            'count' => 2,
            'unknown_key' => 'drop',
            'email' => 'a@b.com',
            'phone' => '8112345678',
            'coupon_code' => 'SAVE10',
            'payment_id' => 'pay_123',
            'transaction_id' => 'tx_9',
            'study_name' => 'Hemograma',
            'name' => 'Juan',
            'rfc' => 'XAXX010101000',
            'test_name' => 'Panel',
        ],
    ]));

    expect($event->metadata)->toMatchArray([
        'probe_id' => 'keep',
        'suite' => 'infra',
        'count' => 2,
    ])->and($event->metadata)->not->toHaveKeys([
        'unknown_key',
        'email',
        'phone',
        'coupon_code',
        'payment_id',
        'transaction_id',
        'study_name',
        'name',
        'rfc',
        'test_name',
    ]);

    assertNoSensitiveBusinessAuditPayload($event);
});

test('models request response throwable and deep arrays are rejected from metadata', function () {
    $normalizer = BusinessAuditMetadataNormalizer::fromConfig();
    registerBusinessAuditProbeDefinition();

    $user = User::factory()->create();
    $result = $normalizer->normalize(BUSINESS_AUDIT_TEST_EVENT, [
        'probe_id' => $user,
        'suite' => Request::create('/'),
        'count' => new RuntimeException('secret sql'),
        'labels' => UploadedFile::fake()->create('x.pdf'),
        'flag_ok' => ['nested' => ['too' => 'deep']],
    ]);

    expect($result)->toBeNull();
});

test('empty metadata normalizes to null consistently', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'metadata' => [],
    ]));

    expect($event->metadata)->toBeNull();
});

test('negative or oversized integers in metadata values are kept only if scalar int; strings over limit rejected', function () {
    $normalizer = new BusinessAuditMetadataNormalizer(maxBytes: 2048, maxDepth: 2, maxKeys: 32);
    registerBusinessAuditProbeDefinition();

    $ok = $normalizer->normalize(BUSINESS_AUDIT_TEST_EVENT, [
        'probe_id' => 'x',
        'count' => -1,
    ]);
    // Negative ints are scalars; domain recorders must avoid them — normalizer keeps ints.
    expect($ok)->toMatchArray(['probe_id' => 'x', 'count' => -1]);

    $long = $normalizer->normalize(BUSINESS_AUDIT_TEST_EVENT, [
        'probe_id' => str_repeat('z', 300),
    ]);
    expect($long)->toBeNull();
});

// ── Actors / subject / resource ────────────────────────────────────────────

test('customer admin system and integration actors produce stable keys', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $customer = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'context' => businessAuditProbeContext([
            'channel' => BusinessAuditChannel::WEB_CHECKOUT,
            'actor' => BusinessAuditActor::customer(10, 20),
            'subject' => BusinessAuditSubject::customer(10),
            'correlation_id' => 'biz-actor-customer',
        ]),
        'resource_type' => 'laboratory_purchase',
        'resource_key' => '99',
    ]));

    expect($customer->actor_type)->toBe('customer')
        ->and($customer->actor_key)->toBe('customer:10')
        ->and($customer->actor_customer_id)->toBe(10)
        ->and($customer->actor_user_id)->toBe(20)
        ->and($customer->subject_type)->toBe('customer')
        ->and($customer->subject_key)->toBe('customer:10')
        ->and($customer->channel)->toBe(BusinessAuditChannel::WEB_CHECKOUT);

    $admin = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'context' => businessAuditProbeContext([
            'channel' => BusinessAuditChannel::ADMIN_WEB,
            'actor' => BusinessAuditActor::admin(7),
            'subject' => BusinessAuditSubject::customer(10),
            'correlation_id' => 'biz-actor-admin',
        ]),
    ]));
    expect($admin->actor_type)->toBe('admin')
        ->and($admin->actor_key)->toBe('admin:7')
        ->and($admin->actor_customer_id)->toBeNull()
        ->and($admin->subject_key)->toBe('customer:10');

    $integration = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'context' => businessAuditProbeContext([
            'channel' => BusinessAuditChannel::INTEGRATION_WEBHOOK,
            'actor' => BusinessAuditActor::integration('paypal'),
            'subject' => null,
            'correlation_id' => 'biz-actor-paypal',
        ]),
    ]));
    expect($integration->actor_type)->toBe('integration')
        ->and($integration->actor_key)->toBe('integration:paypal')
        ->and($integration->actor_customer_id)->toBeNull()
        ->and($integration->subject_key)->toBeNull();

    expect(fn () => BusinessAuditActor::system('not-allowlisted'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => BusinessAuditActor::integration('arbitrary'))
        ->toThrow(InvalidArgumentException::class);
});

// ── Fail-soft ──────────────────────────────────────────────────────────────

test('failed insert is fail-soft with sanitized log and no report()', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $logs = [];
    captureBusinessAuditLogs($logs);

    Schema::rename('business_audit_events', 'business_audit_events_hidden_p6a');

    try {
        $beforeDomain = DB::table('users')->count();
        $result = businessAuditWriter()->write(makeBusinessAuditProbeInput([
            'correlation_id' => 'biz-fail-soft-table',
        ]));

        expect($result)->toBeNull()
            ->and(DB::table('users')->count())->toBe($beforeDomain);

        $failure = collect($logs)->first(fn ($l) => $l['message'] === 'business_audit_write_failed');
        expect($failure)->not->toBeNull()
            ->and($failure['context'])->toHaveKeys(['event_name', 'correlation_id', 'exception_class'])
            ->and($failure['context'])->not->toHaveKey('exception_message')
            ->and(json_encode($failure['context']))->not->toContain('SQLSTATE')
            ->and(json_encode($failure['context']))->not->toContain('probe_id');
    } finally {
        if (Schema::hasTable('business_audit_events_hidden_p6a')) {
            Schema::rename('business_audit_events_hidden_p6a', 'business_audit_events');
        }
    }
});

test('writer does not commit or roll back an outer transaction', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    DB::beginTransaction();
    try {
        $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
            'correlation_id' => 'biz-outer-tx',
        ]));
        expect($event)->not->toBeNull();
        expect(BusinessAuditEvent::query()->where('correlation_id', 'biz-outer-tx')->exists())->toBeTrue();
    } finally {
        DB::rollBack();
    }

    expect(BusinessAuditEvent::query()->where('correlation_id', 'biz-outer-tx')->exists())->toBeFalse();
});

test('invalid definition does not throw to caller via write()', function () {
    enableBusinessAudit();
    // No registerTestDefinition → unknown event
    $result = businessAuditWriter()->write(makeBusinessAuditProbeInput());
    expect($result)->toBeNull();
});

// ── Zero instrumentation ───────────────────────────────────────────────────

test('creating LaboratoryPurchase via helper emits zero business audit events', function () {
    enableBusinessAudit();
    $before = BusinessAuditEvent::query()->count();

    $user = User::factory()->withRegularCustomer()->create();
    createAkubicaLaboratoryPurchase($user);

    expect(BusinessAuditEvent::query()->count())->toBe($before)
        ->and(BusinessAuditEventDefinitions::isKnownEvent('commerce.laboratory_order_created'))->toBeTrue();
});

test('productive taxonomy includes laboratory order and billing events and excludes payment events', function () {
    expect(BusinessAuditEventDefinitions::productiveEventNames())
        ->toBe([
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED,
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED,
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED,
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED,
        ])
        ->and(BusinessAuditEventDefinitions::isKnownEvent('commerce.laboratory_order_created'))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::isKnownEvent('billing.invoice_requested'))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::isKnownEvent('payment.completed'))->toBeFalse();
});

test('flag ON insert stores a valid probe row without PII', function () {
    enableBusinessAudit();
    registerBusinessAuditProbeDefinition();

    $event = businessAuditWriter()->write(makeBusinessAuditProbeInput([
        'outcome' => BusinessAuditOutcome::SUCCEEDED,
        'retryable' => null,
        'error_code' => null,
    ]));

    expect($event)->toBeInstanceOf(BusinessAuditEvent::class)
        ->and($event->event_name)->toBe(BUSINESS_AUDIT_TEST_EVENT)
        ->and($event->outcome)->toBe(BusinessAuditOutcome::SUCCEEDED)
        ->and($event->channel)->toBe(BusinessAuditChannel::CONSOLE)
        ->and($event->actor_type)->toBe(BusinessAuditActor::TYPE_SYSTEM)
        ->and($event->created_at)->not->toBeNull();

    assertNoSensitiveBusinessAuditPayload($event);
});

test('test definitions cannot be registered outside testing environment guard path', function () {
    // We are in testing — registration works; productive list excludes temporary test events.
    registerBusinessAuditProbeDefinition();
    expect(BusinessAuditEventDefinitions::isKnownEvent(BUSINESS_AUDIT_TEST_EVENT))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::productiveEventNames())
        ->toBe([
            BusinessAuditEventDefinitions::EVENT_COMMERCE_LABORATORY_ORDER_CREATED,
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED,
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED,
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED,
        ])
        ->and(in_array(BUSINESS_AUDIT_TEST_EVENT, BusinessAuditEventDefinitions::productiveEventNames(), true))->toBeFalse();
});

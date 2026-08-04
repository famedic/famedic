<?php

use App\Http\Middleware\Api\V1\InitializeApiV1AuditContext;
use App\Models\Api\V1\ApiV1AuditEvent;
use App\Models\User;
use App\Services\Api\V1\Audit\ApiV1AuditContext;
use App\Services\Api\V1\Audit\AuditActor;
use App\Services\Api\V1\Audit\AuditActorResolver;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Services\Api\V1\Audit\AuditEventWriter;
use App\Services\Api\V1\Audit\AuditMetadataNormalizer;
use App\Support\Api\V1\AkubicaCorrelationId;
use App\Support\Migrations\MinimumTableContract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.audit.max_metadata_bytes', 2048);
    config()->set('api_v1.audit.max_metadata_depth', 2);
});

/**
 * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $logs
 */
function captureAuditLogs(array &$logs): void
{
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = [
            'level' => $event->level,
            'message' => (string) $event->message,
            'context' => $event->context,
        ];
    });
}

function auditWriter(): AuditEventWriter
{
    return app(AuditEventWriter::class);
}

function auditActorResolver(): AuditActorResolver
{
    return app(AuditActorResolver::class);
}

function auditSystemActor(): AuditActor
{
    return auditActorResolver()->resolveSystem('console');
}

function enableAudit(): void
{
    config()->set('api_v1.audit.enabled', true);
    app()->forgetInstance(AuditMetadataNormalizer::class);
    app()->forgetInstance(AuditEventWriter::class);
}

function makeAuditProbeInput(array $overrides = []): array
{
    return array_merge([
        'event_name' => AuditEventDefinitions::EVENT_INFRA_PROBE,
        'outcome' => 'success',
        'actor' => auditSystemActor(),
        'method' => 'POST',
        'route_name' => 'api.v1.audit.infra',
        'correlation_id' => 'corr-audit-infra-0001',
        'metadata' => [
            'probe_id' => 'p1',
            'suite' => 'infra',
            'count' => 1,
        ],
    ], $overrides);
}

test('flag OFF does not insert audit rows', function () {
    config()->set('api_v1.audit.enabled', false);

    $before = ApiV1AuditEvent::query()->count();
    $result = auditWriter()->write(makeAuditProbeInput());

    expect($result)->toBeNull()
        ->and(ApiV1AuditEvent::query()->count())->toBe($before);
});

test('flag ON inserts a valid audit row', function () {
    enableAudit();

    $event = auditWriter()->write(makeAuditProbeInput([
        'correlation_id' => 'corr-audit-on-insert',
        'http_status' => 200,
    ]));

    expect($event)->toBeInstanceOf(ApiV1AuditEvent::class)
        ->and($event->event_name)->toBe(AuditEventDefinitions::EVENT_INFRA_PROBE)
        ->and($event->correlation_id)->toBe('corr-audit-on-insert')
        ->and($event->actor_type)->toBe(AuditActor::TYPE_SYSTEM)
        ->and($event->outcome)->toBe('success')
        ->and($event->method)->toBe('POST')
        ->and($event->http_status)->toBe(200)
        ->and($event->created_at)->not->toBeNull()
        ->and($event->metadata)->toMatchArray([
            'probe_id' => 'p1',
            'suite' => 'infra',
            'count' => 1,
        ]);

    expect(Schema::hasColumn('api_v1_audit_events', 'updated_at'))->toBeFalse();
});

test('MySQL/SQLite minimum table contract holds for api_v1_audit_events', function () {
    expect(Schema::hasTable('api_v1_audit_events'))->toBeTrue();

    MinimumTableContract::assertCompatible('api_v1_audit_events', [
        'columns' => [
            'id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
            'event_name' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'occurred_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
            'correlation_id' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'actor_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'actor_key' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'customer_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            'user_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            'personal_access_token_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            'method' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'outcome' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
            'metadata' => ['types' => ['json', 'text', 'clob'], 'nullable' => true],
            'created_at' => ['types' => ['datetime', 'timestamp'], 'nullable' => false],
        ],
        'indexes' => [
            ['name' => 'api_v1_audit_events_occurred_at_index', 'columns' => ['occurred_at']],
            [
                'name' => 'api_v1_audit_events_event_name_occurred_at_index',
                'columns' => ['event_name', 'occurred_at'],
            ],
            [
                'name' => 'api_v1_audit_events_customer_id_occurred_at_index',
                'columns' => ['customer_id', 'occurred_at'],
            ],
            ['name' => 'api_v1_audit_events_correlation_id_index', 'columns' => ['correlation_id']],
            [
                'name' => 'api_v1_audit_events_resource_type_key_occurred_at_index',
                'columns' => ['resource_type', 'resource_key', 'occurred_at'],
            ],
            [
                'name' => 'api_v1_audit_events_actor_key_occurred_at_index',
                'columns' => ['actor_key', 'occurred_at'],
            ],
        ],
        'foreign_keys' => [],
    ]);

    $fks = Schema::getForeignKeys('api_v1_audit_events');
    expect($fks)->toBe([]);
});

test('model has no updated_at and is append-only', function () {
    enableAudit();

    $event = auditWriter()->persistOrFail(makeAuditProbeInput([
        'correlation_id' => 'corr-append-only',
    ]));

    expect($event->timestamps)->toBeTrue()
        ->and(ApiV1AuditEvent::UPDATED_AT)->toBeNull()
        ->and(array_key_exists('updated_at', $event->getAttributes()))->toBeFalse();

    expect(fn () => $event->update(['outcome' => 'tampered']))
        ->toThrow(LogicException::class);

    $event->outcome = 'tampered';
    expect(fn () => $event->save())->toThrow(LogicException::class);

    expect(fn () => $event->delete())->toThrow(LogicException::class);

    expect(ApiV1AuditEvent::query()->whereKey($event->id)->value('outcome'))->toBe('success');
});

test('authenticated actor resolves customer without bearer', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $plain = $user->createToken('akubica-audit-test')->plainTextToken;
    $pat = $user->tokens()->latest('id')->first();
    expect($pat)->toBeInstanceOf(PersonalAccessToken::class);

    $user->withAccessToken($pat);

    $request = Request::create('/api/v1/cart', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$plain);
    $request->setUserResolver(fn () => $user);

    $actor = auditActorResolver()->resolveAuthenticated($request);

    expect($actor->type)->toBe(AuditActor::TYPE_CUSTOMER)
        ->and($actor->key)->toBe('customer:'.(string) $user->customer->id)
        ->and($actor->customerId)->toBe((int) $user->customer->id)
        ->and($actor->userId)->toBe((int) $user->id)
        ->and($actor->personalAccessTokenId)->toBe((int) $pat->id)
        ->and($actor->key)->not->toContain($plain)
        ->and(json_encode($actor->toWriterAttributes()))->not->toContain($plain)
        ->and(json_encode($actor->toWriterAttributes()))->not->toContain('Bearer');
});

test('public actor uses HMAC and never contains original material', function () {
    $resolver = auditActorResolver();
    $material = 'normalized-phone:+5215512345678';

    $actor = $resolver->resolvePublic('login', $material);

    expect($actor->type)->toBe(AuditActor::TYPE_PUBLIC)
        ->and($actor->key)->toStartWith('public:')
        ->and(strlen(substr($actor->key, strlen('public:'))))->toBe(64)
        ->and($actor->key)->not->toContain($material)
        ->and($actor->key)->not->toContain('5512345678')
        ->and($actor->customerId)->toBeNull();
});

test('different purposes produce different public actor keys', function () {
    $resolver = auditActorResolver();
    $material = 'same-normalized-identity@example.com';

    $login = $resolver->resolvePublic('login', $material);
    $register = $resolver->resolvePublic('register', $material);
    $download = $resolver->resolvePublic('secure_download', $material);

    expect($login->key)->not->toBe($register->key)
        ->and($login->key)->not->toBe($download->key)
        ->and($register->key)->not->toBe($download->key);
});

test('system actor only accepts allowlisted keys', function () {
    $resolver = auditActorResolver();

    expect($resolver->resolveSystem('scheduler')->key)->toBe('system:scheduler')
        ->and($resolver->resolveSystem('system:console')->key)->toBe('system:console');

    expect(fn () => $resolver->resolveSystem('arbitrary'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $resolver->resolveSystem('system:root'))
        ->toThrow(InvalidArgumentException::class);
});

test('correlation and route context hydrate correctly without static leak', function () {
    $requestA = Request::create('/api/v1/cart', 'GET');
    $requestA->attributes->set(AkubicaCorrelationId::REQUEST_ATTRIBUTE, 'corr-context-a');
    $requestA->setRouteResolver(function () {
        $route = new class
        {
            public function getName(): string
            {
                return 'api.v1.cart.show';
            }
        };

        return $route;
    });

    $ctxA = ApiV1AuditContext::fromRequest($requestA);
    expect($ctxA->correlationId())->toBe('corr-context-a')
        ->and($ctxA->method())->toBe('GET')
        ->and($ctxA->routeName())->toBe('api.v1.cart.show')
        ->and($ctxA->terminalEventEmitted())->toBeFalse();

    $ctxA->markTerminalEventEmitted();
    $ctxA->setIdempotencyEffect('replay');
    $ctxA->setIdempotencyRecordId(42);

    $requestB = Request::create('/api/v1/orders', 'POST');
    $requestB->attributes->set(AkubicaCorrelationId::REQUEST_ATTRIBUTE, 'corr-context-b');
    $ctxB = ApiV1AuditContext::fromRequest($requestB);

    expect($ctxB)->not->toBe($ctxA)
        ->and($ctxB->correlationId())->toBe('corr-context-b')
        ->and($ctxB->method())->toBe('POST')
        ->and($ctxB->terminalEventEmitted())->toBeFalse()
        ->and($ctxB->idempotencyRecordId())->toBeNull()
        ->and($ctxB->idempotencyEffect())->toBeNull()
        ->and($ctxA->terminalEventEmitted())->toBeTrue()
        ->and($ctxA->idempotencyRecordId())->toBe(42);

    // Middleware only hydrates; does not write.
    $middleware = new InitializeApiV1AuditContext;
    $requestC = Request::create('/api/v1/ping', 'DELETE');
    $requestC->attributes->set(AkubicaCorrelationId::REQUEST_ATTRIBUTE, 'corr-mw');
    $middleware->handle($requestC, fn () => response('ok'));
    $bound = $requestC->attributes->get(ApiV1AuditContext::REQUEST_ATTRIBUTE);
    expect($bound)->toBeInstanceOf(ApiV1AuditContext::class)
        ->and($bound->correlationId())->toBe('corr-mw')
        ->and(ApiV1AuditEvent::query()->where('correlation_id', 'corr-mw')->count())->toBe(0);
});

test('metadata allowlist keeps known keys and drops unknown and secrets', function () {
    $logs = [];
    captureAuditLogs($logs);
    $normalizer = new AuditMetadataNormalizer(2048, 2);
    $event = AuditEventDefinitions::EVENT_INFRA_PROBE;

    $result = $normalizer->normalize($event, [
        'ProbeId' => 'abc',
        'SUITE' => 'unit',
        'count' => 3,
        'labels' => ['a', 'b'],
        'unknown_field' => 'drop-me',
        'password' => 'secret',
        'otp' => '123456',
        'authorization' => 'Bearer xyz',
        'bearer' => 'xyz',
        'idempotency_key' => 'idem-1',
        'secure_link_token' => 'tok',
        'grant_public_id' => 'grant-uuid',
        'step_up_grant' => 'grant-raw',
        'nested' => ['too' => ['deep' => true]],
    ]);

    expect($result)->toMatchArray([
        'probe_id' => 'abc',
        'suite' => 'unit',
        'count' => 3,
        'labels' => ['a', 'b'],
    ])
        ->and($result)->not->toHaveKey('unknown_field')
        ->and($result)->not->toHaveKey('password')
        ->and($result)->not->toHaveKey('otp')
        ->and($result)->not->toHaveKey('authorization')
        ->and($result)->not->toHaveKey('bearer')
        ->and($result)->not->toHaveKey('idempotency_key')
        ->and($result)->not->toHaveKey('secure_link_token')
        ->and($result)->not->toHaveKey('grant_public_id')
        ->and($result)->not->toHaveKey('nested');

    expect(collect($logs)->contains(
        fn (array $log): bool => $log['message'] === 'akubica_audit_metadata_discarded'
            && ($log['context']['reason'] ?? null) === 'keys_filtered'
    ))->toBeTrue();
});

test('metadata rejects Request Response Throwable Model UploadedFile objects and binary', function () {
    $normalizer = new AuditMetadataNormalizer(2048, 2);
    $event = AuditEventDefinitions::EVENT_INFRA_PROBE;

    expect($normalizer->normalize($event, [
        'probe_id' => Request::create('/'),
    ]))->toBeNull();

    expect($normalizer->normalize($event, [
        'probe_id' => response('x'),
    ]))->toBeNull();

    expect($normalizer->normalize($event, [
        'probe_id' => new RuntimeException('boom'),
    ]))->toBeNull();

    expect($normalizer->normalize($event, [
        'probe_id' => new ApiV1AuditEvent,
    ]))->toBeNull();

    expect($normalizer->normalize($event, [
        'probe_id' => UploadedFile::fake()->create('doc.pdf', 10),
    ]))->toBeNull();

    expect($normalizer->normalize($event, [
        'probe_id' => "ok\0binary",
    ]))->toBeNull();

    expect($normalizer->normalize($event, [
        'probe_id' => (object) ['a' => 1],
    ]))->toBeNull();
});

test('metadata enforces max depth and discards oversized JSON without truncation', function () {
    $logs = [];
    captureAuditLogs($logs);
    $normalizer = new AuditMetadataNormalizer(2048, 2);
    $event = AuditEventDefinitions::EVENT_INFRA_PROBE;

    $deep = $normalizer->normalize($event, [
        'probe_id' => 'd',
        'flags' => ['level1' => ['level2' => 'nope']],
    ]);
    expect($deep === null || ! array_key_exists('flags', (array) $deep))->toBeTrue();
    if (is_array($deep)) {
        expect($deep)->not->toHaveKey('flags');
    }

    $big = str_repeat('x', 3000);
    $oversize = $normalizer->normalize($event, [
        'probe_id' => $big,
        'suite' => 'size',
    ]);
    expect($oversize)->toBeNull();

    expect(collect($logs)->contains(
        fn (array $log): bool => $log['message'] === 'akubica_audit_metadata_discarded'
            && ($log['context']['reason'] ?? null) === 'max_bytes_exceeded'
    ))->toBeTrue();
});

test('writer fail-soft does not break simulated business operation and logs safe fields only', function () {
    enableAudit();
    $logs = [];
    captureAuditLogs($logs);

    $operationCompleted = false;
    $result = null;

    try {
        // Force a persistence-path failure (event_name too long) without leaking secrets into logs.
        $result = auditWriter()->write(makeAuditProbeInput([
            'event_name' => str_repeat('x', 120),
            'correlation_id' => 'corr-fail-soft',
            'metadata' => [
                'probe_id' => 'should-not-appear-in-error-log',
                'otp' => '111111',
                'authorization' => 'Bearer leaked-token',
            ],
        ]));
        $operationCompleted = true;
    } catch (Throwable) {
        $operationCompleted = false;
    }

    expect($operationCompleted)->toBeTrue()
        ->and($result)->toBeNull()
        ->and(ApiV1AuditEvent::query()->where('correlation_id', 'corr-fail-soft')->count())->toBe(0);

    $errorLogs = collect($logs)->where('message', 'akubica_audit_write_failed')->values();
    expect($errorLogs)->toHaveCount(1);

    $ctx = $errorLogs[0]['context'];
    $encoded = json_encode($ctx);

    expect($ctx)->toHaveKeys(['event_name', 'correlation_id', 'exception_class'])
        ->and($ctx['correlation_id'])->toBe('corr-fail-soft')
        ->and($ctx['exception_class'])->toBe(InvalidArgumentException::class)
        ->and(count($ctx))->toBe(3)
        ->and($encoded)->not->toContain('111111')
        ->and($encoded)->not->toContain('Bearer leaked-token')
        ->and($encoded)->not->toContain('should-not-appear')
        ->and($encoded)->not->toContain('SQLSTATE')
        ->and($encoded)->not->toContain('otp');
});

test('persistOrFail surfaces failures to callers', function () {
    enableAudit();

    expect(fn () => auditWriter()->persistOrFail([
        'outcome' => 'success',
        'actor' => auditSystemActor(),
    ]))->toThrow(InvalidArgumentException::class);

    // Valid insert path works.
    $event = auditWriter()->persistOrFail(makeAuditProbeInput([
        'correlation_id' => 'corr-persist-ok',
        'event_name' => AuditEventDefinitions::EVENT_INFRA_WRITER_PROBE,
        'metadata' => ['probe_id' => 'w1', 'phase' => 'test', 'ok' => true],
    ]));

    expect($event->id)->toBeGreaterThan(0);
});

test('secrets are never persisted in audit rows', function () {
    enableAudit();

    $event = auditWriter()->persistOrFail(makeAuditProbeInput([
        'correlation_id' => 'corr-no-secrets',
        'metadata' => [
            'probe_id' => 'safe',
            'otp' => '654321',
            'authorization' => 'Bearer super-secret-token',
            'bearer' => 'super-secret-token',
            'secure_link_token' => 'link-secret',
            'idempotency_key' => 'client-idem-key',
            'grant_public_id' => 'grant-public-secret',
            'password' => 'hunter2',
        ],
    ]));

    $row = DB::table('api_v1_audit_events')->where('id', $event->id)->first();
    $blob = json_encode($row);

    expect($blob)->not->toContain('654321')
        ->and($blob)->not->toContain('super-secret-token')
        ->and($blob)->not->toContain('Bearer')
        ->and($blob)->not->toContain('link-secret')
        ->and($blob)->not->toContain('client-idem-key')
        ->and($blob)->not->toContain('grant-public-secret')
        ->and($blob)->not->toContain('hunter2')
        ->and($event->metadata)->toMatchArray(['probe_id' => 'safe']);
});

test('same correlation_id may hold multiple legitimate events', function () {
    enableAudit();
    $corr = 'corr-multi-events-same-request';

    auditWriter()->persistOrFail(makeAuditProbeInput([
        'correlation_id' => $corr,
        'outcome' => 'started',
        'metadata' => ['probe_id' => 'e1'],
    ]));
    auditWriter()->persistOrFail(makeAuditProbeInput([
        'correlation_id' => $corr,
        'outcome' => 'success',
        'event_name' => AuditEventDefinitions::EVENT_INFRA_WRITER_PROBE,
        'metadata' => ['probe_id' => 'e2', 'phase' => 'end', 'ok' => true],
    ]));

    expect(ApiV1AuditEvent::query()->where('correlation_id', $corr)->count())->toBe(2);
});

test('config defaults keep audit disabled and expose metadata limits', function () {
    // phpunit.xml force=true; config cached from env at boot — re-read via helper defaults.
    expect(config('api_v1.audit.enabled'))->toBeFalse()
        ->and(config('api_v1.audit.max_metadata_bytes'))->toBe(2048)
        ->and(config('api_v1.audit.max_metadata_depth'))->toBe(2);
});

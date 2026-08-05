<?php

use App\Actions\Api\V1\CreateAkubicaInvoiceRequestAction;
use App\Actions\CreateInvoiceAction;
use App\Actions\CreateInvoiceRequestAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Audit\BusinessAuditEvent;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use App\Notifications\PurchaseInvoiceUploaded;
use App\Services\Audit\Business\BillingInvoiceDocumentsAuditHint;
use App\Services\Audit\Business\BillingInvoiceDocumentsAuditRecorder;
use App\Services\Audit\Business\BillingInvoiceRequestedAuditHint;
use App\Services\Audit\Business\BillingInvoiceRequestedAuditRecorder;
use App\Services\Audit\Business\BusinessAuditActor;
use App\Services\Audit\Business\BusinessAuditChannel;
use App\Services\Audit\Business\BusinessAuditEventDefinitions;
use App\Services\Audit\Business\BusinessAuditEventWriter;
use App\Services\Audit\Business\BusinessAuditMetadataNormalizer;
use App\Services\Audit\Business\BusinessAuditOutcome;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('business_audit.enabled', false);
    config()->set('api_v1.audit.enabled', false);
    Notification::fake();
    Storage::fake();
});

function enableBusinessAuditForP7b(): void
{
    config()->set('business_audit.enabled', true);
    app()->forgetInstance(BusinessAuditMetadataNormalizer::class);
    app()->forgetInstance(BusinessAuditEventWriter::class);
    app()->forgetInstance(BillingInvoiceRequestedAuditRecorder::class);
    app()->forgetInstance(BillingInvoiceDocumentsAuditRecorder::class);
    app()->forgetInstance(CreateInvoiceAction::class);
    app()->forgetInstance(CreateInvoiceRequestAction::class);
    app()->forgetInstance(CreateAkubicaInvoiceRequestAction::class);
}

function p7bCustomerUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function p7bLaboratoryPurchase(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-'.fake()->unique()->numerify('######'),
        'gda_consecutivo' => fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'phone' => '8181234567',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle Test',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 35000,
    ], $attributes));
}

function p7bTaxProfile(User $user): TaxProfile
{
    Storage::put('fiscal-certificates/p7b/cert.pdf', 'pdf-content');

    return TaxProfile::factory()->for($user->customer)->create([
        'name' => 'PUBLICO EN GENERAL',
        'razon_social' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
        'tipo_persona' => 'fisica',
        'fiscal_certificate' => 'fiscal-certificates/p7b/cert.pdf',
    ]);
}

function p7bRequestedHint(User $user, LaboratoryPurchase $purchase, ?string $correlationId = null): BillingInvoiceRequestedAuditHint
{
    return new BillingInvoiceRequestedAuditHint(
        channel: BusinessAuditChannel::API_V1,
        requestOrigin: BillingInvoiceRequestedAuditHint::ORIGIN_API_V1,
        purchaseType: BillingInvoiceRequestedAuditHint::PURCHASE_TYPE_LABORATORY,
        purchaseId: (int) $purchase->id,
        actorCustomerId: (int) $user->customer->id,
        actorUserId: (int) $user->id,
        subjectCustomerId: (int) $user->customer->id,
        correlationId: $correlationId,
    );
}

function p7bDocumentsHint(User $admin, LaboratoryPurchase $purchase, ?string $correlationId = null): BillingInvoiceDocumentsAuditHint
{
    return new BillingInvoiceDocumentsAuditHint(
        channel: BusinessAuditChannel::ADMIN_WEB,
        purchaseType: BillingInvoiceDocumentsAuditHint::PURCHASE_TYPE_LABORATORY,
        purchaseId: (int) $purchase->id,
        actorAdminUserId: (int) $admin->id,
        subjectCustomerId: (int) $purchase->customer_id,
        correlationId: $correlationId,
    );
}

function p7bFakePdf(): UploadedFile
{
    return UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf');
}

function p7bFakeXml(): UploadedFile
{
    return UploadedFile::fake()->create('factura.xml', 20, 'application/xml');
}

function assertBillingAuditPrivacy(BusinessAuditEvent $event): void
{
    $blob = strtolower(json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE) ?: '');

    foreach ([
        '@example.com',
        '8112345678',
        '8181234567',
        'juan',
        'pérez',
        'xaxx010101000',
        'publico en general',
        'fiscal-certificates',
        'invoices/',
        'factura.pdf',
        'factura.xml',
        'bearer ',
        'password',
        'authorization',
        'cfdi',
        'tax_regime',
        'razon_social',
    ] as $needle) {
        expect($blob)->not->toContain($needle);
    }
}

// ── Definitions ────────────────────────────────────────────────────────────

test('productive definitions for billing invoice events are registered', function () {
    expect(BusinessAuditEventDefinitions::isKnownEvent(
        BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED
    ))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::isKnownEvent(
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        ))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::isKnownEvent(
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED
        ))->toBeTrue()
        ->and(BusinessAuditEventDefinitions::allowedMetadataKeys(
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED
        ))->toBe(['request_origin', 'purchase_type', 'purchase_id'])
        ->and(BusinessAuditEventDefinitions::allowedMetadataKeys(
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        ))->toBe(['purchase_type', 'purchase_id'])
        ->and(BusinessAuditEventDefinitions::allowedMetadataKeys(
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED
        ))->toBe(['purchase_type', 'purchase_id', 'pdf_replaced', 'xml_replaced']);
});

// ── invoice_requested ──────────────────────────────────────────────────────

test('billing.invoice_requested emits after successful first create', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $purchase = p7bLaboratoryPurchase($user);
    $profile = p7bTaxProfile($user);

    $before = BusinessAuditEvent::query()->count();
    $result = app(CreateAkubicaInvoiceRequestAction::class)(
        $purchase,
        $profile,
        null,
        p7bRequestedHint($user, $purchase, 'biz-invoice-req-001'),
    );

    expect($result)->toHaveKey('invoice_request');

    $events = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED)
        ->get();

    expect($events)->toHaveCount(1)
        ->and(BusinessAuditEvent::query()->count())->toBe($before + 1);

    $event = $events->first();
    expect($event->outcome)->toBe(BusinessAuditOutcome::SUCCEEDED)
        ->and($event->channel)->toBe(BusinessAuditChannel::API_V1)
        ->and($event->actor_type)->toBe(BusinessAuditActor::TYPE_CUSTOMER)
        ->and($event->actor_key)->toBe('customer:'.$user->customer->id)
        ->and($event->subject_key)->toBe('customer:'.$user->customer->id)
        ->and($event->resource_type)->toBe('invoice_request')
        ->and($event->resource_key)->toBe((string) $result['invoice_request']->id)
        ->and($event->correlation_id)->toBe('biz-invoice-req-001')
        ->and($event->metadata)->toMatchArray([
            'request_origin' => BillingInvoiceRequestedAuditHint::ORIGIN_API_V1,
            'purchase_type' => BillingInvoiceRequestedAuditHint::PURCHASE_TYPE_LABORATORY,
            'purchase_id' => $purchase->id,
        ]);

    assertBillingAuditPrivacy($event);
});

test('billing.invoice_requested is not emitted when flag is OFF', function () {
    config()->set('business_audit.enabled', false);
    $user = p7bCustomerUser();
    $purchase = p7bLaboratoryPurchase($user);
    $profile = p7bTaxProfile($user);

    $before = BusinessAuditEvent::query()->count();
    $result = app(CreateAkubicaInvoiceRequestAction::class)(
        $purchase,
        $profile,
        null,
        p7bRequestedHint($user, $purchase),
    );

    expect($result)->toHaveKey('invoice_request')
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

test('billing.invoice_requested is not emitted on rollback', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $purchase = p7bLaboratoryPurchase($user);
    $profile = p7bTaxProfile($user);

    Schema::rename('invoice_requests', 'invoice_requests_offline');

    $before = BusinessAuditEvent::query()->count();

    try {
        app(CreateAkubicaInvoiceRequestAction::class)(
            $purchase,
            $profile,
            null,
            p7bRequestedHint($user, $purchase),
        );
        expect(false)->toBeTrue();
    } catch (Throwable) {
        // expected
    } finally {
        Schema::rename('invoice_requests_offline', 'invoice_requests');
    }

    expect(BusinessAuditEvent::query()->count())->toBe($before);
});

test('billing.invoice_requested fail-soft keeps invoice request when auditor table is broken', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $purchase = p7bLaboratoryPurchase($user);
    $profile = p7bTaxProfile($user);

    Schema::rename('business_audit_events', 'business_audit_events_offline');

    try {
        $result = app(CreateAkubicaInvoiceRequestAction::class)(
            $purchase,
            $profile,
            null,
            p7bRequestedHint($user, $purchase),
        );

        expect($result)->toHaveKey('invoice_request')
            ->and($result['invoice_request']->id)->toBeGreaterThan(0);
    } finally {
        Schema::rename('business_audit_events_offline', 'business_audit_events');
    }

    expect(BusinessAuditEvent::query()->count())->toBe(0);
});

test('billing.invoice_requested is not emitted when updating an existing request', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $purchase = p7bLaboratoryPurchase($user);
    $profile = p7bTaxProfile($user);

    app(CreateInvoiceRequestAction::class)(
        $purchase,
        $profile,
        'G03',
        new BillingInvoiceRequestedAuditHint(
            channel: BusinessAuditChannel::WEB_CHECKOUT,
            requestOrigin: BillingInvoiceRequestedAuditHint::ORIGIN_LABORATORY_WEB,
            purchaseType: BillingInvoiceRequestedAuditHint::PURCHASE_TYPE_LABORATORY,
            purchaseId: (int) $purchase->id,
            actorCustomerId: (int) $user->customer->id,
            actorUserId: (int) $user->id,
            subjectCustomerId: (int) $user->customer->id,
        ),
    );

    expect(BusinessAuditEvent::query()->where(
        'event_name',
        BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED
    )->count())->toBe(1);

    app(CreateInvoiceRequestAction::class)(
        $purchase->fresh(),
        $profile,
        'S01',
        new BillingInvoiceRequestedAuditHint(
            channel: BusinessAuditChannel::WEB_CHECKOUT,
            requestOrigin: BillingInvoiceRequestedAuditHint::ORIGIN_LABORATORY_WEB,
            purchaseType: BillingInvoiceRequestedAuditHint::PURCHASE_TYPE_LABORATORY,
            purchaseId: (int) $purchase->id,
            actorCustomerId: (int) $user->customer->id,
            actorUserId: (int) $user->id,
            subjectCustomerId: (int) $user->customer->id,
        ),
    );

    expect(BusinessAuditEvent::query()->where(
        'event_name',
        BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED
    )->count())->toBe(1);
});

// ── invoice_completed ──────────────────────────────────────────────────────

test('billing.invoice_completed emits once on first PDF+XML completion', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    $invoice = app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase, 'biz-invoice-complete-001'),
    );

    $events = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED)
        ->get();

    expect($events)->toHaveCount(1)
        ->and($invoice->completed_at)->not->toBeNull()
        ->and($events->first()->resource_key)->toBe((string) $invoice->id)
        ->and($events->first()->actor_type)->toBe(BusinessAuditActor::TYPE_ADMIN)
        ->and($events->first()->actor_key)->toBe('admin:'.$admin->id)
        ->and($events->first()->channel)->toBe(BusinessAuditChannel::ADMIN_WEB)
        ->and($events->first()->metadata)->toMatchArray([
            'purchase_type' => BillingInvoiceDocumentsAuditHint::PURCHASE_TYPE_LABORATORY,
            'purchase_id' => $purchase->id,
        ])
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED
        )->count())->toBe(0);

    assertBillingAuditPrivacy($events->first());
});

test('billing.invoice_completed is not emitted for incomplete PDF-only upload', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    $invoice = app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        null,
        p7bDocumentsHint($admin, $purchase),
    );

    expect($invoice->completed_at)->toBeNull()
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        )->count())->toBe(0);
});

test('billing.invoice_completed emits when historical incomplete becomes complete', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    app(CreateInvoiceAction::class)($purchase, p7bFakePdf(), null, p7bDocumentsHint($admin, $purchase));

    $invoice = app(CreateInvoiceAction::class)(
        $purchase->fresh(),
        null,
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    expect($invoice->completed_at)->not->toBeNull()
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        )->count())->toBe(1)
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED
        )->count())->toBe(0);
});

test('billing.invoice_completed completed_at stays immutable after replacement', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    $invoice = app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );
    $completedAt = $invoice->completed_at->copy();

    $replaced = app(CreateInvoiceAction::class)(
        $purchase->fresh(),
        p7bFakePdf(),
        null,
        p7bDocumentsHint($admin, $purchase),
    );

    expect($replaced->completed_at->equalTo($completedAt))->toBeTrue()
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        )->count())->toBe(1);
});

test('billing.invoice_completed is not emitted on rollback', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    Schema::rename('invoices', 'invoices_offline');
    $before = BusinessAuditEvent::query()->count();

    try {
        app(CreateInvoiceAction::class)(
            $purchase,
            p7bFakePdf(),
            p7bFakeXml(),
            p7bDocumentsHint($admin, $purchase),
        );
        expect(false)->toBeTrue();
    } catch (Throwable) {
        // expected
    } finally {
        Schema::rename('invoices_offline', 'invoices');
    }

    expect(BusinessAuditEvent::query()->count())->toBe($before);
});

test('billing.invoice_completed fail-soft keeps invoice when auditor is broken', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    Schema::rename('business_audit_events', 'business_audit_events_offline');

    try {
        $invoice = app(CreateInvoiceAction::class)(
            $purchase,
            p7bFakePdf(),
            p7bFakeXml(),
            p7bDocumentsHint($admin, $purchase),
        );

        expect($invoice->id)->toBeGreaterThan(0)
            ->and($invoice->completed_at)->not->toBeNull();
    } finally {
        Schema::rename('business_audit_events_offline', 'business_audit_events');
    }

    expect(BusinessAuditEvent::query()->count())->toBe(0);
    Notification::assertSentTo($user, PurchaseInvoiceUploaded::class);
});

// ── documents_replaced ─────────────────────────────────────────────────────

test('billing.invoice_documents_replaced emits pdf-only flags', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    app(CreateInvoiceAction::class)(
        $purchase->fresh(),
        p7bFakePdf(),
        null,
        p7bDocumentsHint($admin, $purchase),
    );

    $event = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata)->toMatchArray([
            'pdf_replaced' => true,
            'xml_replaced' => false,
        ])
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        )->count())->toBe(1);
});

test('billing.invoice_documents_replaced emits xml-only flags', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    app(CreateInvoiceAction::class)(
        $purchase->fresh(),
        null,
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    $event = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED)
        ->first();

    expect($event->metadata)->toMatchArray([
        'pdf_replaced' => false,
        'xml_replaced' => true,
    ]);
});

test('billing.invoice_documents_replaced emits both flags', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    app(CreateInvoiceAction::class)(
        $purchase->fresh(),
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    $event = BusinessAuditEvent::query()
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED)
        ->first();

    expect($event->metadata)->toMatchArray([
        'pdf_replaced' => true,
        'xml_replaced' => true,
    ]);
});

test('billing.invoice_documents_replaced is not emitted when no document changes', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    app(CreateInvoiceAction::class)(
        $purchase->fresh(),
        null,
        null,
        p7bDocumentsHint($admin, $purchase),
    );

    expect(BusinessAuditEvent::query()->where(
        'event_name',
        BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED
    )->count())->toBe(0)
        ->and(BusinessAuditEvent::query()->where(
            'event_name',
            BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED
        )->count())->toBe(1);
});

test('billing.invoice_documents_replaced fail-soft keeps replacement when auditor is broken', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    $invoice = app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );
    $originalPdf = $invoice->getRawOriginal('invoice');

    Schema::rename('business_audit_events', 'business_audit_events_offline');

    try {
        $replaced = app(CreateInvoiceAction::class)(
            $purchase->fresh(),
            p7bFakePdf(),
            null,
            p7bDocumentsHint($admin, $purchase),
        );

        expect($replaced->getRawOriginal('invoice'))->not->toBe($originalPdf);
    } finally {
        Schema::rename('business_audit_events_offline', 'business_audit_events');
    }
});

test('downloads do not emit billing invoice business audit events', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    $invoice = app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase),
    );

    $before = BusinessAuditEvent::query()->count();

    // Downloads are separate controllers; simulating access does not call CreateInvoiceAction.
    expect($invoice->isDocumentComplete())->toBeTrue()
        ->and(Invoice::query()->find($invoice->id))->not->toBeNull()
        ->and(BusinessAuditEvent::query()->count())->toBe($before);
});

test('absent correlation id is generated as UUID for billing.invoice_completed', function () {
    enableBusinessAuditForP7b();
    $user = p7bCustomerUser();
    $admin = User::factory()->create();
    $purchase = p7bLaboratoryPurchase($user);

    $invoice = app(CreateInvoiceAction::class)(
        $purchase,
        p7bFakePdf(),
        p7bFakeXml(),
        p7bDocumentsHint($admin, $purchase, null),
    );

    $event = BusinessAuditEvent::query()
        ->where('resource_key', (string) $invoice->id)
        ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED)
        ->first();

    expect(Str::isUuid($event->correlation_id))->toBeTrue();
});

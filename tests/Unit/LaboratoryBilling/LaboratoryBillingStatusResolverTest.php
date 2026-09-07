<?php

use App\Enums\LaboratoryBillingDocumentStatus;
use App\Enums\LaboratoryBillingStatus;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['famedic.laboratory_billing.invoice_delay_threshold_business_days' => 3]);
});

it('resuelve estados documentales', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);

    expect($resolver->documentStatus(null))->toBe(LaboratoryBillingDocumentStatus::NoDocuments);

    $complete = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => 'invoices/a.xml']);
    expect($resolver->documentStatus($complete))->toBe(LaboratoryBillingDocumentStatus::Complete);

    $missingXml = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => null]);
    expect($resolver->documentStatus($missingXml))->toBe(LaboratoryBillingDocumentStatus::MissingXml);

    $missingPdf = new Invoice(['invoice' => null, 'invoice_xml' => 'invoices/a.xml']);
    expect($resolver->documentStatus($missingPdf))->toBe(LaboratoryBillingDocumentStatus::MissingPdf);
});

it('marca atrasada solo cuando no está completa y supera el umbral', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $now = Carbon::parse('2026-08-10 12:00:00', 'America/Monterrey');

    $request = new InvoiceRequest(['created_at' => Carbon::parse('2026-08-04 11:59:00', 'America/Monterrey')]);
    $incomplete = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => null]);
    $complete = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => 'invoices/a.xml']);

    expect($resolver->resolve($request, $incomplete, $now))->toBe(LaboratoryBillingStatus::Overdue);
    expect($resolver->resolve($request, $complete, $now))->toBe(LaboratoryBillingStatus::Completed);
    expect($resolver->daysOverdue($request->created_at, $complete, $now))->toBeNull();
    expect($resolver->daysOverdue($request->created_at, $incomplete, $now))->toBe(2);
});

it('calcula vencimiento con 3 dias habiles conservando hora y cruzando fin de semana', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $requestedAt = Carbon::parse('2026-08-07 16:00:00', 'America/Monterrey');

    expect($resolver->dueAt($requestedAt)?->toDateTimeString())->toBe('2026-08-12 16:00:00');
    expect($resolver->isOverdue($requestedAt, null, Carbon::parse('2026-08-12 16:00:00', 'America/Monterrey')))->toBeFalse();
    expect($resolver->isOverdue($requestedAt, null, Carbon::parse('2026-08-12 16:00:01', 'America/Monterrey')))->toBeTrue();
});

it('calcula vencimientos de lunes a viernes sin contar fines de semana ni feriados', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);

    $cases = [
        ['2026-08-03 10:00:00', '2026-08-06 10:00:00'], // lunes a jueves
        ['2026-08-05 10:00:00', '2026-08-10 10:00:00'], // miercoles a lunes
        ['2026-08-06 10:00:00', '2026-08-11 10:00:00'], // jueves a martes
        ['2026-08-07 16:00:00', '2026-08-12 16:00:00'], // viernes a miercoles
    ];

    foreach ($cases as [$requestedAt, $expectedDueAt]) {
        expect($resolver->dueAt(Carbon::parse($requestedAt, 'America/Monterrey'))?->toDateTimeString())
            ->toBe($expectedDueAt);
    }
});

it('clasifica completada solo con pdf y xml, independientemente de completed_at', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $request = new InvoiceRequest(['created_at' => Carbon::parse('2026-08-01 10:00:00', 'America/Monterrey')]);
    $now = Carbon::parse('2026-08-10 12:00:00', 'America/Monterrey');

    $completeWithDate = new Invoice([
        'invoice' => 'invoices/a.pdf',
        'invoice_xml' => 'invoices/a.xml',
        'completed_at' => Carbon::parse('2026-08-02 10:00:00', 'America/Monterrey'),
    ]);
    $completeLegacy = new Invoice([
        'invoice' => 'invoices/a.pdf',
        'invoice_xml' => 'invoices/a.xml',
        'completed_at' => null,
    ]);
    $onlyPdf = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => null]);
    $onlyXml = new Invoice(['invoice' => null, 'invoice_xml' => 'invoices/a.xml']);
    $none = new Invoice(['invoice' => null, 'invoice_xml' => null]);

    expect($resolver->resolve($request, $completeWithDate, $now))->toBe(LaboratoryBillingStatus::Completed);
    expect($resolver->resolve($request, $completeLegacy, $now))->toBe(LaboratoryBillingStatus::Completed);
    expect($resolver->resolve($request, $onlyPdf, $now))->toBe(LaboratoryBillingStatus::Overdue);
    expect($resolver->resolve($request, $onlyXml, $now))->toBe(LaboratoryBillingStatus::Overdue);
    expect($resolver->resolve($request, $none, $now))->toBe(LaboratoryBillingStatus::Overdue);
    expect($resolver->resolve($request, null, $now))->toBe(LaboratoryBillingStatus::Overdue);
});

it('no considera completada una factura si falta pdf o xml aunque tenga completed_at', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $request = new InvoiceRequest(['created_at' => Carbon::parse('2026-08-01 10:00:00', 'America/Monterrey')]);
    $invoice = new Invoice([
        'invoice' => 'invoices/a.pdf',
        'invoice_xml' => null,
        'completed_at' => Carbon::parse('2026-08-02 10:00:00', 'America/Monterrey'),
    ]);

    expect($resolver->isComplete($invoice))->toBeFalse();
    expect($resolver->resolve($request, $invoice, Carbon::parse('2026-08-10 12:00:00', 'America/Monterrey')))->toBe(LaboratoryBillingStatus::Overdue);
});

it('clasifica pendiente y en proceso dentro del plazo', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $now = Carbon::parse('2026-08-10 12:00:00', 'America/Monterrey');
    $request = new InvoiceRequest(['created_at' => $now->copy()->subDay()]);

    expect($resolver->resolve($request, null, $now))->toBe(LaboratoryBillingStatus::Pending);
    expect($resolver->resolve(
        $request,
        new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => null]),
        $now
    ))->toBe(LaboratoryBillingStatus::InProgress);
});

it('calcula tiempo de respuesta en horas usando completed_at', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $request = new InvoiceRequest(['created_at' => Carbon::parse('2026-08-01 10:00:00')]);
    $invoice = new Invoice([
        'created_at' => Carbon::parse('2026-08-01 12:00:00'),
        'completed_at' => Carbon::parse('2026-08-02 10:00:00'),
        'updated_at' => Carbon::parse('2026-08-05 10:00:00'),
    ]);

    expect($resolver->responseTimeHours($request, $invoice))->toBe(24.0);
});

it('no calcula tiempo de respuesta sin completed_at', function () {
    $resolver = app(LaboratoryBillingStatusResolver::class);
    $request = new InvoiceRequest(['created_at' => Carbon::parse('2026-08-01 10:00:00')]);
    $invoice = new Invoice([
        'invoice' => 'invoices/a.pdf',
        'invoice_xml' => 'invoices/a.xml',
        'created_at' => Carbon::parse('2026-08-02 10:00:00'),
        'completed_at' => null,
    ]);

    expect($resolver->responseTimeHours($request, $invoice))->toBeNull();
});

<?php

use App\Enums\LaboratoryBillingDocumentStatus;
use App\Enums\LaboratoryBillingStatus;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['famedic.laboratory_billing.invoice_delay_threshold_days' => 3]);
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

    $request = new InvoiceRequest(['created_at' => $now->copy()->subDays(5)]);
    $incomplete = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => null]);
    $complete = new Invoice(['invoice' => 'invoices/a.pdf', 'invoice_xml' => 'invoices/a.xml']);

    expect($resolver->resolve($request, $incomplete, $now))->toBe(LaboratoryBillingStatus::Overdue);
    expect($resolver->resolve($request, $complete, $now))->toBe(LaboratoryBillingStatus::Completed);
    expect($resolver->daysOverdue($request->created_at, $complete, $now))->toBeNull();
    expect($resolver->daysOverdue($request->created_at, $incomplete, $now))->toBe(2);
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

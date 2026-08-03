<?php

namespace App\Services\LaboratoryBilling;

use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;

class LaboratoryBillingPresenter
{
    public function __construct(
        private LaboratoryBillingStatusResolver $resolver,
    ) {}

    public function presentRequest(InvoiceRequest $request): array
    {
        /** @var LaboratoryPurchase|null $purchase */
        $purchase = $request->invoiceRequestable;
        $invoice = $purchase?->invoice;
        $customer = $purchase?->customer;
        $user = $customer?->user;
        $taxProfile = $request->taxProfile;
        $billing = $this->resolver->present($request, $invoice);

        $patientName = trim(collect([
            $purchase?->name,
            $purchase?->paternal_lastname,
            $purchase?->maternal_lastname,
        ])->filter()->implode(' ')) ?: null;

        $customerName = $user
            ? trim(collect([$user->name, $user->paternal_lastname, $user->maternal_lastname])->filter()->implode(' '))
            : null;

        return [
            'id' => $request->id,
            'requested_at' => $request->created_at?->toIso8601String(),
            'formatted_requested_at' => $request->formatted_created_at,
            'snapshot' => [
                'name' => $request->name,
                'rfc' => $request->rfc,
                'zipcode' => $request->zipcode,
                'tax_regime' => $request->tax_regime,
                'formatted_tax_regime' => $request->formatted_tax_regime,
                'cfdi_use' => $request->cfdi_use,
                'formatted_cfdi_use' => $request->formatted_cfdi_use,
            ],
            'patient_name' => $patientName ?: ($customerName ?: '—'),
            'customer_email' => $user?->email,
            'purchase' => $purchase ? [
                'id' => $purchase->id,
                'gda_order_id' => $purchase?->gda_order_id,
                'folio' => $purchase?->gda_order_id ?: (string) $purchase->id,
                'brand' => $purchase->brand?->value ?? $purchase->brand,
                'brand_label' => method_exists($purchase->brand, 'label') ? $purchase->brand->label() : ($purchase->brand ?? '—'),
                'formatted_total' => $purchase->formatted_total ?? null,
                'total_cents' => $purchase->total_cents ?? null,
                'deleted_at' => $purchase->deleted_at?->toIso8601String(),
                'show_url' => route('admin.laboratory-purchases.show', ['laboratory_purchase' => $purchase->id]),
            ] : null,
            'customer' => $customer && $user ? [
                'id' => $customer->id,
                'name' => $customerName,
                'email' => $user->email,
                'show_url' => route('admin.customers.show', ['customer' => $customer->id]),
            ] : null,
            'tax_profile' => $taxProfile ? $this->presentTaxProfileSummary($taxProfile) : null,
            'invoice' => $invoice ? $this->presentInvoice($invoice) : null,
            'billing' => $billing,
            'detail_url' => $purchase
                ? route('admin.laboratory-purchases.show', ['laboratory_purchase' => $purchase->id])
                : null,
        ];
    }

    public function presentInvoice(Invoice $invoice): array
    {
        $hasPdf = $this->resolver->hasPdf($invoice);
        $hasXml = $this->resolver->hasXml($invoice);

        return [
            'id' => $invoice->id,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'completed_at' => $invoice->completed_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
            'formatted_created_at' => $invoice->formatted_created_at,
            'formatted_completed_at' => $invoice->formatted_completed_at,
            'formatted_updated_at' => $invoice->formatted_updated_at,
            'has_pdf' => $hasPdf,
            'has_xml' => $hasXml,
            'pdf_url' => $hasPdf ? route('invoice', ['invoice' => $invoice->id]) : null,
            'xml_url' => $hasXml ? route('invoice.xml', ['invoice' => $invoice->id]) : null,
        ];
    }

    public function presentTaxProfileSummary(TaxProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name ?: $profile->razon_social,
            'razon_social' => $profile->razon_social ?: $profile->name,
            'rfc' => $profile->rfc,
            'tipo_persona' => $profile->tipo_persona,
            'tipo_persona_label' => $profile->tipo_persona_formatted,
            'is_default' => (bool) $profile->is_default,
            'is_active' => $profile->deleted_at === null,
            'deleted_at' => $profile->deleted_at?->toIso8601String(),
            'show_url' => route('admin.laboratory-billing.tax-profiles.show', ['tax_profile' => $profile->id]),
        ];
    }

    public function presentTaxProfile(TaxProfile $profile): array
    {
        $summary = $this->presentTaxProfileSummary($profile);
        $customer = $profile->customer;
        $user = $customer?->user;

        return array_merge($summary, [
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'formatted_tax_regime' => $profile->formatted_tax_regime,
            'cfdi_use' => $profile->cfdi_use,
            'formatted_cfdi_use' => $profile->formatted_cfdi_use,
            'estatus_sat' => $profile->estatus_sat,
            'domicilio_fiscal' => $profile->domicilio_fiscal,
            'created_at' => $profile->created_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
            'formatted_created_at' => localizedDate($profile->created_at)?->isoFormat('D MMM Y h:mm a'),
            'formatted_updated_at' => localizedDate($profile->updated_at)?->isoFormat('D MMM Y h:mm a'),
            'has_fiscal_certificate' => filled($profile->fiscal_certificate),
            'fiscal_certificate_url' => filled($profile->fiscal_certificate)
                ? route('admin.tax-profiles.fiscal-certificate', ['tax_profile' => $profile->id])
                : null,
            'customer' => $customer && $user ? [
                'id' => $customer->id,
                'name' => trim(collect([$user->name, $user->paternal_lastname, $user->maternal_lastname])->filter()->implode(' ')),
                'email' => $user->email,
                'show_url' => route('admin.customers.show', ['customer' => $customer->id]),
            ] : null,
            'invoice_requests_count' => (int) ($profile->invoice_requests_count ?? $profile->invoiceRequests()->count()),
            'is_used' => ($profile->invoice_requests_count ?? null) !== null
                ? (int) $profile->invoice_requests_count > 0
                : $profile->isUsed(),
        ]);
    }
}

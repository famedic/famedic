<?php

namespace Tests\Unit\TaxProfiles;

use App\Models\InvoiceRequest;
use App\Models\TaxProfile;
use App\Support\TaxProfiles\FiscalCertificateStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalCertificateStorageTest extends TestCase
{
    public function test_effective_certificate_falls_back_to_linked_tax_profile(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        Storage::put('fiscal-certificates/perfil.pdf', '%PDF-fake');

        $taxProfile = new TaxProfile([
            'fiscal_certificate' => 'fiscal-certificates/perfil.pdf',
        ]);

        $invoiceRequest = new InvoiceRequest([
            'fiscal_certificate' => 'invoice-requests/perfil.pdf',
        ]);
        $invoiceRequest->setRelation('taxProfile', $taxProfile);

        $availability = FiscalCertificateStorage::availabilityFor($invoiceRequest, $taxProfile);

        $this->assertFalse($availability['invoice_request']['exists']);
        $this->assertTrue($availability['tax_profile']['exists']);
        $this->assertSame('tax_profile', $availability['effective']['source']);
        $this->assertTrue($availability['effective']['exists']);
        $this->assertTrue($availability['effective']['uses_fallback']);
    }

    public function test_effective_certificate_prefers_invoice_request_snapshot_when_present(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        Storage::put('invoice-requests/snapshot.pdf', '%PDF-snapshot');
        Storage::put('fiscal-certificates/perfil.pdf', '%PDF-profile');

        $taxProfile = new TaxProfile([
            'fiscal_certificate' => 'fiscal-certificates/perfil.pdf',
        ]);

        $invoiceRequest = new InvoiceRequest([
            'fiscal_certificate' => 'invoice-requests/snapshot.pdf',
        ]);
        $invoiceRequest->setRelation('taxProfile', $taxProfile);

        $availability = FiscalCertificateStorage::availabilityFor($invoiceRequest, $taxProfile);

        $this->assertTrue($availability['invoice_request']['exists']);
        $this->assertSame('invoice_request', $availability['effective']['source']);
        $this->assertFalse($availability['effective']['uses_fallback']);
    }
}

<?php

namespace App\Exports\Sheets;

use App\Models\OnlinePharmacyPurchase;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OnlinePharmacyPurchasesSheet implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private array $filters = []
    ) {}

    public function query(): Builder
    {
        return OnlinePharmacyPurchase::with([
            'transactions',
            'customer.user',
            'customer.customerable',
            'invoice',
            'invoiceRequest',
            'vendorPayments',
        ])
            ->withCount('onlinePharmacyPurchaseItems')
            ->filter($this->filters)
            ->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return [
            'Identificador',
            'Cliente',
            'Tipo de cuenta',
            'Quien recibe',
            'Cantidad de productos',
            'Subtotal',
            'Impuestos',
            'Costo de envío',
            'Comisión',
            'Total',
            'Método de pago',
            'Referencia de pago',
            'Fecha de compra',
            'Fecha de entrega',
            'Solicitud de factura',
            'Fecha factura subida',
            'Pago a proveedor',
        ];
    }

    public function map($onlinePharmacyPurchase): array
    {
        $transaction = $onlinePharmacyPurchase->transactions()->first();
        $totalCents = $onlinePharmacyPurchase->total_cents;
        $feeCents = 0;

        if ($transaction) {
            $feeCents = $transaction->commission_cents;
            $totalCents = $totalCents - $feeCents;
        }

        return [
            $onlinePharmacyPurchase->vitau_order_id,
            $onlinePharmacyPurchase->customer->user->full_name,
            $onlinePharmacyPurchase->customer->formatted_account_type,
            $onlinePharmacyPurchase->full_name,
            $onlinePharmacyPurchase->online_pharmacy_purchase_items_count,
            numberCents($onlinePharmacyPurchase->subtotal_cents),
            numberCents($onlinePharmacyPurchase->tax_cents),
            numberCents($onlinePharmacyPurchase->shipping_price_cents),
            numberCents($feeCents),
            numberCents($totalCents),
            $transaction ? match ($transaction->payment_method) {
                'stripe' => 'Pago con tarjeta',
                'odessa' => 'Caja de ahorro',
                default => $transaction->payment_method,
            } : '',
            $transaction?->reference_id ?? '',
            $onlinePharmacyPurchase->created_at ? Date::dateTimeToExcel(localizedDate($onlinePharmacyPurchase->created_at)) : null,
            $onlinePharmacyPurchase->expected_delivery_date ? Date::dateTimeToExcel(localizedDate($onlinePharmacyPurchase->expected_delivery_date)) : null,
            $onlinePharmacyPurchase->invoiceRequest?->created_at ? Date::dateTimeToExcel(localizedDate($onlinePharmacyPurchase->invoiceRequest->created_at)) : null,
            $onlinePharmacyPurchase->invoice?->created_at ? Date::dateTimeToExcel(localizedDate($onlinePharmacyPurchase->invoice->created_at)) : null,
            $onlinePharmacyPurchase->vendorPayments->first()?->paid_at ? Date::dateTimeToExcel(localizedDate($onlinePharmacyPurchase->vendorPayments->first()->paid_at)) : null,
        ];
    }

    public function title(): string
    {
        return 'Pedidos';
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_CURRENCY_USD,  // Subtotal
            'G' => NumberFormat::FORMAT_CURRENCY_USD,  // Impuestos
            'H' => NumberFormat::FORMAT_CURRENCY_USD,  // Costo de envío
            'I' => NumberFormat::FORMAT_CURRENCY_USD,  // Comisión
            'J' => NumberFormat::FORMAT_CURRENCY_USD,  // Total
            'M' => NumberFormat::FORMAT_DATE_XLSX15,   // Fecha de compra
            'N' => NumberFormat::FORMAT_DATE_XLSX15,   // Fecha de entrega
            'O' => NumberFormat::FORMAT_DATE_XLSX15,   // Solicitud de factura
            'P' => NumberFormat::FORMAT_DATE_XLSX15,   // Fecha factura subida
            'Q' => NumberFormat::FORMAT_DATE_XLSX15,   // Pago a proveedor
        ];
    }
}

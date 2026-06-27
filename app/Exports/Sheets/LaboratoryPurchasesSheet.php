<?php

namespace App\Exports\Sheets;

use App\Models\Coupon;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class LaboratoryPurchasesSheet implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    /** @var array<int, string|null> */
    private array $couponCodeById = [];

    public function __construct(
        private array $filters = []
    ) {}

    public function query(): Builder
    {
        return LaboratoryPurchase::with([
            'transactions',
            'customer.user',
            'customer.customerable',
            'invoice',
            'invoiceRequest',
            'laboratoryAppointment',
            'vendorPayments',
        ])
            ->withCount('laboratoryPurchaseItems')
            ->filter($this->filters)
            ->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return [
            'Identificador',
            'Marca',
            'Cliente',
            'Tipo de cuenta',
            'Paciente',
            'Cantidad de pruebas',
            'Subtotal',
            'Comisión total EfevooPay',
            'Total',
            'Método de pago',
            'Referencia de pago',
            'Crédito a favor aplicado',
            'Código cupón (referencia)',
            'Fecha de compra',
            'Solicitud de factura',
            'Carga de factura',
            'Carga de resultados',
            'Cita en sucursal',
            'Pago a proveedor',
            'Estado',
            'Fecha de cancelación',
        ];
    }

    public function map($laboratoryPurchase): array
    {
        $transaction = $laboratoryPurchase->transactions->first();
        $feeCents = 0;
        $totalCents = $laboratoryPurchase->total_cents;

        if ($transaction) {
            $feeCents = $transaction->exportCommissionCents($laboratoryPurchase->total_cents);
            $totalCents = $laboratoryPurchase->total_cents - $feeCents;
        }

        $creditAppliedCents = (int) ($laboratoryPurchase->coupon_discount_cents ?? 0);
        if ($creditAppliedCents === 0) {
            $creditAppliedCents = (int) data_get($transaction?->details, 'coupon_amount_cents', 0);
        }

        $couponReference = $this->couponReferenceForTransaction($transaction);

        return [
            $laboratoryPurchase->gda_order_id,
            $laboratoryPurchase->brand->value,
            $laboratoryPurchase->customer->user->full_name,
            $laboratoryPurchase->customer->formatted_account_type,
            $laboratoryPurchase->full_name,
            $laboratoryPurchase->laboratory_purchase_items_count,
            numberCents($laboratoryPurchase->total_cents),
            numberCents($feeCents),
            numberCents($totalCents),
            $transaction ? match ($transaction->payment_method) {
                'stripe' => 'Pago con tarjeta',
                'odessa' => 'Caja de ahorro',
                'paypal' => 'PayPal',
                'efevoopay' => 'EfevooPay',
                'coupon_balance' => 'Crédito a favor (cupón)',
                default => $transaction->payment_method,
            } : '',
            $transaction?->reference_id ?? '',
            $creditAppliedCents > 0 ? numberCents($creditAppliedCents) : '',
            $couponReference,
            $laboratoryPurchase->created_at ? Date::dateTimeToExcel(localizedDate($laboratoryPurchase->created_at)) : null,
            $laboratoryPurchase->invoiceRequest?->created_at ? Date::dateTimeToExcel(localizedDate($laboratoryPurchase->invoiceRequest->created_at)) : null,
            $laboratoryPurchase->invoice?->created_at ? Date::dateTimeToExcel(localizedDate($laboratoryPurchase->invoice->created_at)) : null,
            $this->getResultsUploadedDate($laboratoryPurchase) ? Date::dateTimeToExcel(localizedDate($this->getResultsUploadedDate($laboratoryPurchase))) : null,
            $laboratoryPurchase->laboratoryAppointment?->appointment_date ? Date::dateTimeToExcel(localizedDate($laboratoryPurchase->laboratoryAppointment->appointment_date)) : null,
            $laboratoryPurchase->vendorPayments->first()?->paid_at ? Date::dateTimeToExcel(localizedDate($laboratoryPurchase->vendorPayments->first()->paid_at)) : null,
            $laboratoryPurchase->trashed() ? 'Cancelada' : 'Activa',
            $laboratoryPurchase->deleted_at
                ? Date::dateTimeToExcel(localizedDate($laboratoryPurchase->deleted_at))
                : null,
        ];
    }

    public function title(): string
    {
        return 'Pedidos';
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_CURRENCY_USD,
            'H' => NumberFormat::FORMAT_CURRENCY_USD,
            'I' => NumberFormat::FORMAT_CURRENCY_USD,
            'L' => NumberFormat::FORMAT_CURRENCY_USD,
            'N' => NumberFormat::FORMAT_DATE_XLSX15,
            'O' => NumberFormat::FORMAT_DATE_XLSX15,
            'P' => NumberFormat::FORMAT_DATE_XLSX15,
            'Q' => NumberFormat::FORMAT_DATE_XLSX15,
            'R' => NumberFormat::FORMAT_DATE_XLSX15,
            'S' => NumberFormat::FORMAT_DATE_XLSX14,
            'U' => NumberFormat::FORMAT_DATE_XLSX15,
        ];
    }

    private function couponReferenceForTransaction(?Transaction $transaction): string
    {
        if (! $transaction) {
            return '';
        }

        $couponId = data_get($transaction->details, 'coupon_id');
        if (! $couponId) {
            return '';
        }

        $couponId = (int) $couponId;

        if (! array_key_exists($couponId, $this->couponCodeById)) {
            $this->couponCodeById[$couponId] = Coupon::query()
                ->whereKey($couponId)
                ->value('code');
        }

        $code = $this->couponCodeById[$couponId];

        if ($code !== null && $code !== '') {
            return (string) $code;
        }

        return 'ID:' . $couponId;
    }

    private function getResultsUploadedDate(LaboratoryPurchase $laboratoryPurchase): ?Carbon
    {
        if (! $laboratoryPurchase->results) {
            return null;
        }

        try {
            $timestamp = Storage::lastModified($laboratoryPurchase->results);

            return Carbon::createFromTimestamp($timestamp);
        } catch (\Exception $e) {
            return null;
        }
    }
}

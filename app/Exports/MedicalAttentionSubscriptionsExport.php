<?php

namespace App\Exports;

use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class MedicalAttentionSubscriptionsExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping
{
    public Collection $subscriptions;

    public function __construct(Collection $subscriptions)
    {
        $this->subscriptions = $subscriptions;
    }

    public function collection()
    {
        return $this->subscriptions;
    }

    public function headings(): array
    {
        return [
            'Identificador Médico',
            'Cliente',
            'Correo electrónico',
            'Teléfono',
            'Tipo de cliente',
            'Miembros Cubiertos',
            'Precio',
            'Fecha Inicio',
            'Fecha Fin',
            'Activa',
            'Método de Pago',
            'Fecha de Creación',
            'Empresa ID (Odessa)',
        ];
    }

    public function map($subscription): array
    {
        $customer = $subscription->customer;
        $user = $customer->user;
        $customerable = $customer->customerable;

        // Get customer name based on type
        $customerName = $customer->customerable_type === FamilyAccount::class
            ? $customerable?->full_name
            : $user?->full_name;

        // Count covered members (main customer + family members)
        $coveredMembers = 1; // Main customer
        if ($customer->familyMembers) {
            $coveredMembers += $customer->familyMembers->count();
        }

        // Get payment method from first transaction
        $transaction = $subscription->transactions()->first();

        // Check if subscription is currently active
        $now = now();
        $isActive = $subscription->start_date <= $now && $subscription->end_date >= $now;

        // Get Odessa company ID if applicable
        $odessaCompanyId = $customer->customerable_type === OdessaAfiliateAccount::class
            ? $customerable?->odessa_afiliated_company_id
            : null;

        return [
            $customer->medical_attention_identifier,
            $customerName,
            $user?->email,
            $user?->phone?->formatNational(),
            $customer->formatted_account_type,
            $coveredMembers,
            numberCents($subscription->price_cents),
            $subscription->start_date ? Date::dateTimeToExcel(localizedDate($subscription->start_date)) : null,
            $subscription->end_date ? Date::dateTimeToExcel(localizedDate($subscription->end_date)) : null,
            $isActive ? 'Sí' : 'No',
            $transaction ? match ($transaction->payment_method) {
                'stripe' => 'Pago con tarjeta',
                'odessa' => 'Caja de ahorro',
                default => $transaction->payment_method,
            } : '',
            $subscription->created_at ? Date::dateTimeToExcel(localizedDate($subscription->created_at)) : null,
            $odessaCompanyId,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_CURRENCY_USD,
            'H' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'I' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'L' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }
}

<?php

namespace App\Exports;

use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CustomersExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithColumnFormatting, WithHeadings, WithMapping
{
    public array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        return Customer::with([
            'user',
            'customerable',
        ])
            ->withCount(['laboratoryPurchases', 'onlinePharmacyPurchases'])
            ->withSum('laboratoryPurchases', 'total_cents')
            ->withSum('onlinePharmacyPurchases', 'total_cents')
            ->filter($this->filters)
            ->orderByDesc('created_at');
    }

    public function chunkSize(): int
    {
        return 10;
    }

    public function headings(): array
    {
        return [
            'Nombre',
            'Apellido paterno',
            'Apellido materno',
            'Género',
            'Fecha de nacimiento',
            'Correo electrónico',
            'Teléfono',
            'Tipo de cliente',
            'Membresía médica',
            'Membresía médica titular',
            'Expiración de membresía médica',
            'Compras de laboratorio',
            'Acumulado compras de laboratorio',
            'Compras de farmacia',
            'Acumulado compras de farmacia',
            'Fecha de creación',
            'Odessa ID',
            'Socio ID',
            'Empresa ID',
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->customerable_type === FamilyAccount::class ? $customer->customerable?->name : $customer->user?->name,
            $customer->customerable_type === FamilyAccount::class ? $customer->customerable?->paternal_lastname : $customer->user?->paternal_lastname,
            $customer->customerable_type === FamilyAccount::class ? $customer->customerable?->maternal_lastname : $customer->user?->maternal_lastname,
            $customer->customerable_type === FamilyAccount::class ? $customer->customerable?->formatted_gender : $customer->user?->formatted_gender,
            ($customer->customerable_type === FamilyAccount::class ? $customer->customerable?->birth_date : $customer->user?->birth_date) ? Date::dateTimeToExcel(localizedDate($customer->customerable_type === FamilyAccount::class ? $customer->customerable->birth_date : $customer->user->birth_date)) : null,
            $customer->user?->email,
            $customer->user?->phone?->formatNational(),
            $customer->formatted_account_type,
            $customer->medical_attention_identifier,
            $customer->customerable_type === FamilyAccount::class ? $customer->customerable->parentCustomer->medical_attention_identifier : null,
            $customer->medical_attention_subscription_expires_at ? Date::dateTimeToExcel(localizedDate($customer->medical_attention_subscription_expires_at)) : null,
            $customer->laboratory_purchases_count,
            numberCents($customer->laboratory_purchases_sum_total_cents),
            $customer->online_pharmacy_purchases_count,
            numberCents($customer->online_pharmacy_purchases_sum_total_cents),
            $customer->created_at ? Date::dateTimeToExcel(localizedDate($customer->created_at)) : null,
            $customer->customerable_type === OdessaAfiliateAccount::class ? $customer->customerable?->odessa_identifier : null,
            $customer->customerable_type === OdessaAfiliateAccount::class ? $customer->customerable?->partner_identifier : null,
            $customer->customerable_type === OdessaAfiliateAccount::class ? $customer->customerable?->odessa_afiliated_company_id : null,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'K' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'M' => NumberFormat::FORMAT_CURRENCY_USD,
            'O' => NumberFormat::FORMAT_CURRENCY_USD,
            'P' => NumberFormat::FORMAT_DATE_XLSX15,
        ];
    }
}

<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PaymentAuthenticationAttemptsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Support reference',
            'Attempt UUID',
            'Customer ID',
            'Correo',
            'Estado',
            'Categoría',
            'Origen',
            'Certeza',
            'Número de intento',
            'Inicio',
            'Fin',
            'Duración (s)',
            'Llamadas externas',
            'GetLink',
            'Polls',
            'Tokenización',
            'Duplicados bloqueados',
            'Provider order ID',
            'Código',
            'Mensaje',
            'Recuperado en reintento',
            'Proveedor',
            'Recovery context type',
            'Recovery context status',
            'Recovery eligible',
            'Recovery started',
            'Selected recovery action',
            'Authentication recovered',
            'Payment recovered',
            'Recovery method confirmed',
            'Attempt count in context',
            'Card verified at',
            'Recovered at',
            'Time to authentication recovery (s)',
            'Time to payment recovery (s)',
            'Internal transaction ID',
            'Limit reached',
            'Confirmation pending',
            'Posible verificación duplicada',
        ];
    }

    public function map($row): array
    {
        return [
            $row['support_reference'] ?? '',
            $row['attempt_uuid'] ?? '',
            $row['customer_id'] ?? '',
            $row['email'] ?? '',
            $row['status'] ?? '',
            $row['result_category'] ?? '',
            $row['failure_origin'] ?? '',
            $row['failure_certainty'] ?? '',
            $row['attempt_number'] ?? '',
            $row['started_at_local'] ?? '',
            $row['finished_at_local'] ?? '',
            $row['duration_seconds'] ?? '',
            $row['external_call_count'] ?? '',
            $row['provider_link_call_count'] ?? '',
            $row['status_poll_call_count'] ?? '',
            $row['tokenization_call_count'] ?? '',
            $row['duplicate_request_count'] ?? '',
            $row['provider_order_id'] ?? '',
            $row['provider_code'] ?? '',
            $row['provider_message'] ?? '',
            ($row['recovered_on_retry'] ?? false) ? 'sí' : 'no',
            $row['provider'] ?? '',
            $row['recovery_context_type'] ?? '',
            $row['recovery_context_status'] ?? '',
            $row['recovery_eligible'] ?? '',
            $row['recovery_started'] ?? '',
            $row['selected_recovery_action'] ?? '',
            $row['authentication_recovered'] ?? '',
            $row['payment_recovered'] ?? '',
            $row['recovery_method_confirmed'] ?? '',
            $row['attempt_count_in_context'] ?? '',
            $row['card_verified_at_local'] ?? '',
            $row['recovered_at_local'] ?? '',
            $row['time_to_authentication_recovery_seconds'] ?? '',
            $row['time_to_payment_recovery_seconds'] ?? '',
            $row['internal_transaction_id'] ?? '',
            $row['limit_reached'] ?? '',
            $row['confirmation_pending'] ?? '',
            $row['possible_duplicate_verification'] ?? '',
        ];
    }
}

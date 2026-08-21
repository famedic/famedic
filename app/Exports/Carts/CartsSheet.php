<?php

namespace App\Exports\Carts;

use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exports\Carts\Concerns\BuildsCartExportQuery;
use App\Exports\Carts\Concerns\FormatsCartExportSheet;
use App\Models\Cart;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\PaymentAttempt;
use App\Services\Carts\CartOperationalInsightResolver;
use App\Services\Carts\CartPaymentAttemptCorrelator;
use Carbon\Carbon;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CartsSheet implements FromGenerator, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use BuildsCartExportQuery;
    use FormatsCartExportSheet;

    private const CHUNK_SIZE = 50;

    public function __construct(
        private array $filters = []
    ) {}

    public function generator(): Generator
    {
        $page = 1;
        $paymentCorrelator = app(CartPaymentAttemptCorrelator::class);
        $operationalResolver = app(CartOperationalInsightResolver::class);

        do {
            $carts = (clone $this->baseCartQuery())->forPage($page, self::CHUNK_SIZE)->get();
            $paymentInsights = $paymentCorrelator->forCarts($carts);

            foreach ($carts as $cart) {
                yield $this->row($cart, $paymentInsights[(int) $cart->id] ?? null, $operationalResolver);
            }

            $page++;
        } while ($carts->count() === self::CHUNK_SIZE);
    }

    public function headings(): array
    {
        return [
            'ID carrito',
            'Usuario',
            'Correo',
            'Tipo cliente',
            'Compras anteriores',
            'Tipo carrito',
            'Marca',
            'Numero de estudios/items',
            'Estudios',
            'Total carrito',
            'Fecha creacion',
            'Ultima actividad',
            'Tiempo sin actividad',
            'Estado',
            'Etapa checkout',
            'Avance checkout',
            'Paciente seleccionado',
            'Direccion seleccionada',
            'Metodo de pago seleccionado',
            'Requiere cita',
            'Situacion actual',
            'Tiene cita',
            'Estado cita',
            'ID cita',
            'Fecha cita',
            'Hora cita',
            'Sucursal',
            'Cita confirmada',
            'Tiempo esperando confirmacion',
            'Intento llamar',
            'Fecha intento llamada',
            'Solicito llamada',
            'Disponible desde',
            'Disponible hasta',
            'Comentario callback',
            'Tiene intento de pago',
            'Gateway',
            'Estado ultimo intento',
            'Numero de intentos',
            'Codigo procesador',
            'Mensaje pago',
            'Fecha ultimo intento',
            'Tipo correlacion pago',
            'Atencion requerida',
            'Categoria atencion',
            'Razon atencion',
            'Accion sugerida',
            'Fecha registro',
        ];
    }

    public function title(): string
    {
        return 'Carritos';
    }

    public function columnFormats(): array
    {
        return [
            'J' => NumberFormat::FORMAT_CURRENCY_USD,
            'K' => NumberFormat::FORMAT_DATE_XLSX22,
            'L' => NumberFormat::FORMAT_DATE_XLSX22,
            'Y' => NumberFormat::FORMAT_DATE_XLSX22,
            'AB' => NumberFormat::FORMAT_DATE_XLSX22,
            'AE' => NumberFormat::FORMAT_DATE_XLSX22,
            'AG' => NumberFormat::FORMAT_DATE_XLSX22,
            'AH' => NumberFormat::FORMAT_DATE_XLSX22,
            'AP' => NumberFormat::FORMAT_DATE_XLSX22,
            'AV' => NumberFormat::FORMAT_DATE_XLSX22,
        ];
    }

    private function row(
        Cart $cart,
        ?array $paymentInsight,
        CartOperationalInsightResolver $operationalResolver,
    ): array {
        $appointment = $cart->type === MonitoringCartType::Lab
            ? $cart->laboratoryAppointmentsForDisplay()->first()
            : null;
        $draft = $this->checkoutDraftForCart($cart);
        $checkout = $this->checkoutSummary($cart, $draft, $appointment, $paymentInsight);
        $payment = $this->paymentSummary($paymentInsight);
        $operational = $operationalResolver->resolve($cart, $paymentInsight);

        return [
            $cart->id,
            $cart->user?->full_name,
            $cart->user?->email,
            $this->customerSegmentLabel($cart),
            $this->previousPurchasesCount($cart),
            $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            $this->brandLabel($cart),
            $cart->items_count ?? $cart->items->count(),
            $cart->items->pluck('name')->filter()->implode(' | '),
            (float) $cart->total,
            $this->excelDate($cart->created_at),
            $this->excelDate($cart->updated_at),
            $cart->inactiveForLabel(),
            $cart->displayStatusLabel(),
            $checkout['stage'],
            $checkout['progress'],
            $checkout['has_patient'],
            $checkout['has_address'],
            $checkout['has_payment_method'],
            $this->requiresAppointment($cart) ? 'Si' : 'No',
            $checkout['situation'],
            $appointment ? 'Si' : 'No',
            $this->appointmentStatus($cart, $appointment),
            $appointment?->id,
            $this->excelDate($appointment?->appointment_date),
            $appointment?->appointment_date_time,
            $appointment?->laboratoryStore?->name,
            $this->excelDate($appointment?->confirmed_at),
            $appointment?->confirmed_at === null ? $this->appointmentWaitingLabel($appointment) : null,
            $appointment?->phone_call_intent_at ? 'Si' : 'No',
            $this->excelDate($appointment?->phone_call_intent_at),
            $appointment?->has_left_callback_info ? 'Si' : 'No',
            $this->excelDate($appointment?->callback_availability_starts_at),
            $this->excelDate($appointment?->callback_availability_ends_at),
            $this->shortText($appointment?->patient_callback_comment, 180),
            $payment['has_attempt'],
            $payment['gateway'],
            $payment['status'],
            $payment['attempts_count'],
            $payment['processor_code'],
            $payment['processor_message'],
            $payment['last_attempt_at'],
            $payment['correlation_type'],
            $operational['requires_attention'] ? 'Si' : 'No',
            $operational['category'],
            $operational['reason'],
            $operational['recommended_action'],
            $this->excelDate($cart->user?->created_at),
        ];
    }

    private function checkoutDraftForCart(Cart $cart): ?LaboratoryCheckoutDraft
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return null;
        }

        $drafts = $cart->user?->customer?->laboratoryCheckoutDrafts ?? collect();
        $brandValues = collect($cart->labBrands())->pluck('value')->filter()->values();

        return $drafts
            ->when(
                $brandValues->isNotEmpty(),
                fn ($rows) => $rows->filter(fn (LaboratoryCheckoutDraft $draft) => $brandValues->contains($draft->laboratory_brand?->value)),
            )
            ->sortByDesc('updated_at')
            ->first();
    }

    private function checkoutSummary(Cart $cart, ?LaboratoryCheckoutDraft $draft, ?LaboratoryAppointment $appointment, ?array $paymentInsight): array
    {
        if ($cart->displayStatus() === 'completed') {
            return [
                'stage' => 'Compra completada',
                'progress' => '5/5',
                'has_patient' => 'Si',
                'has_address' => 'Si',
                'has_payment_method' => 'Si',
                'situation' => 'Compra completada',
            ];
        }

        if ($cart->type !== MonitoringCartType::Lab) {
            return [
                'stage' => 'Carrito activo',
                'progress' => null,
                'has_patient' => null,
                'has_address' => null,
                'has_payment_method' => null,
                'situation' => 'Farmacia',
            ];
        }

        $step = $draft?->checkout_step;
        $completedSteps = match ($step) {
            'address' => 1,
            'appointment' => 2,
            'payment' => 3,
            'confirmation' => 4,
            default => 0,
        };

        if ($appointment) {
            $completedSteps = max($completedSteps, $appointment->confirmed_at ? 3 : 2);
        }

        if (($paymentInsight['should_display'] ?? false) === true) {
            $completedSteps = max($completedSteps, 4);
        }

        return [
            'stage' => $this->checkoutStepLabel($step),
            'progress' => $completedSteps.'/5',
            'has_patient' => $draft?->contact_id ? 'Si' : 'No',
            'has_address' => $draft?->address_id ? 'Si' : 'No',
            'has_payment_method' => filled($draft?->payment_method) ? 'Si' : 'No',
            'situation' => $this->currentSituation($cart, $appointment, $paymentInsight, $step),
        ];
    }

    private function currentSituation(Cart $cart, ?LaboratoryAppointment $appointment, ?array $paymentInsight, ?string $step): string
    {
        if (($paymentInsight['should_display'] ?? false) === true) {
            return match ($paymentInsight['status'] ?? null) {
                PaymentAttempt::STATUS_DECLINED => 'Pago rechazado',
                PaymentAttempt::STATUS_ERROR => 'Error tecnico de pago',
                PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => 'Intento de pago pendiente',
                PaymentAttempt::STATUS_APPROVED => 'Pago aprobado',
                PaymentAttempt::STATUS_REFUNDED => 'Pago reembolsado',
                default => 'Pago no determinado',
            };
        }

        if ($appointment?->confirmed_at !== null && $appointment->laboratory_purchase_id === null) {
            return 'Cita confirmada sin pago';
        }

        if ($appointment?->confirmed_at === null && $appointment !== null) {
            return 'Cita pendiente de confirmacion';
        }

        if (! empty($step)) {
            return ($cart->displayStatus() === 'abandoned' ? 'Abandono en ' : 'En ').mb_strtolower($this->checkoutStepLabel($step));
        }

        return 'Checkout sin avance';
    }

    private function checkoutStepLabel(?string $step): string
    {
        return match ($step) {
            'patient' => 'Paciente',
            'address' => 'Direccion',
            'appointment' => 'Cita',
            'payment' => 'Pago',
            'confirmation' => 'Confirmacion',
            default => 'Sin avance',
        };
    }

    private function appointmentStatus(Cart $cart, ?LaboratoryAppointment $appointment): string
    {
        if ($cart->type !== MonitoringCartType::Lab || ! $this->requiresAppointment($cart)) {
            return 'No aplica';
        }

        if (! $appointment) {
            return 'Sin cita';
        }

        if ($appointment->confirmed_at === null) {
            return 'Pendiente';
        }

        return $appointment->laboratory_purchase_id === null ? 'Confirmada sin pago' : 'Confirmada';
    }

    private function paymentSummary(?array $paymentInsight): array
    {
        if ($paymentInsight === null) {
            return [
                'has_attempt' => 'No',
                'gateway' => null,
                'status' => 'Sin informacion',
                'attempts_count' => 0,
                'processor_code' => null,
                'processor_message' => null,
                'last_attempt_at' => null,
                'correlation_type' => 'No determinada',
            ];
        }

        if (($paymentInsight['confidence'] ?? null) === 'ambiguous') {
            return [
                'has_attempt' => 'Si',
                'gateway' => null,
                'status' => 'No determinada',
                'attempts_count' => (int) ($paymentInsight['attempts_count'] ?? 0),
                'processor_code' => null,
                'processor_message' => null,
                'last_attempt_at' => null,
                'correlation_type' => 'No determinada',
            ];
        }

        $lastAttempt = $paymentInsight['last_attempt'] ?? [];

        return [
            'has_attempt' => 'Si',
            'gateway' => $paymentInsight['gateway'] ?? null,
            'status' => $this->paymentStatusLabel($paymentInsight['status'] ?? null),
            'attempts_count' => (int) ($paymentInsight['attempts_count'] ?? 0),
            'processor_code' => $lastAttempt['processor_code'] ?? null,
            'processor_message' => $lastAttempt['processor_message'] ?? null,
            'last_attempt_at' => $this->excelDate(! empty($lastAttempt['occurred_at']) ? Carbon::parse($lastAttempt['occurred_at']) : null),
            'correlation_type' => match ($paymentInsight['confidence'] ?? null) {
                'explicit' => 'Explicita',
                'legacy_high' => 'Legacy confiable',
                default => 'No determinada',
            },
        ];
    }

    private function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => 'Pendiente',
            PaymentAttempt::STATUS_APPROVED => 'Aprobado',
            PaymentAttempt::STATUS_DECLINED => 'Rechazado',
            PaymentAttempt::STATUS_ERROR => 'Error tecnico',
            PaymentAttempt::STATUS_REFUNDED => 'Reembolsado',
            default => 'No determinada',
        };
    }

    private function previousPurchasesCount(Cart $cart): int
    {
        return (int) ($cart->previous_laboratory_purchases_count ?? 0)
            + (int) ($cart->previous_online_pharmacy_purchases_count ?? 0);
    }

    private function customerSegmentLabel(Cart $cart): string
    {
        return match (true) {
            $this->previousPurchasesCount($cart) >= 2 => 'Cliente recurrente',
            $this->previousPurchasesCount($cart) === 1 => 'Cliente existente',
            default => 'Cliente nuevo',
        };
    }

    private function brandLabel(Cart $cart): ?string
    {
        return collect($cart->labBrands())->pluck('label')->filter()->implode(', ') ?: null;
    }

    private function requiresAppointment(Cart $cart): bool
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return false;
        }

        return $cart->items->contains(fn ($item) => (bool) $item->laboratoryTest?->requires_appointment);
    }

    private function appointmentWaitingLabel(?LaboratoryAppointment $appointment): ?string
    {
        if (! $appointment || $appointment->confirmed_at !== null || ! $appointment->created_at) {
            return null;
        }

        $minutes = max(0, $appointment->created_at->diffInMinutes(now()));

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);

        return $hours < 24 ? $hours.' h' : intdiv($hours, 24).' d';
    }

    private function shortText(?string $value, int $limit): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 3).'...' : $value;
    }

    private function excelDate($date): ?float
    {
        return $date ? Date::dateTimeToExcel(localizedDate($date)) : null;
    }

    protected function freezePaneCell(): string
    {
        return 'D2';
    }

    protected function wrappedColumns(): array
    {
        return [
            'I' => 45,
            'AI' => 45,
            'AO' => 36,
            'AU' => 32,
        ];
    }
}

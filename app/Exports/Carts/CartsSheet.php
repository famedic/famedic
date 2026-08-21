<?php

namespace App\Exports\Carts;

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
            'Requiere atencion',
            'Tipo de atencion',
            'Motivo de atencion',
            'Accion sugerida',
            'Ultimo dispositivo',
            'Sistema operativo',
            'Navegador',
            'Cambio de dispositivo',
            'Dispositivos detectados',
            'Tiene cita',
            'Estado cita',
            'ID cita',
            'Fecha creacion cita',
            'Hora creacion cita',
            'Fecha cita',
            'Hora cita',
            'Fecha confirmacion cita',
            'Hora confirmacion cita',
            'Sucursal',
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
            'AH' => 'dd/mm/yyyy',
            'AI' => 'hh:mm',
            'AJ' => 'dd/mm/yyyy',
            'AK' => 'hh:mm',
            'AL' => 'dd/mm/yyyy',
            'AM' => 'hh:mm',
            'AQ' => NumberFormat::FORMAT_DATE_XLSX22,
            'AS' => NumberFormat::FORMAT_DATE_XLSX22,
            'AT' => NumberFormat::FORMAT_DATE_XLSX22,
            'BB' => NumberFormat::FORMAT_DATE_XLSX22,
            'BD' => NumberFormat::FORMAT_DATE_XLSX22,
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
        $clientContext = $this->clientContext($cart);

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
            $operational['requires_attention'] ? 'Si' : 'No',
            $this->attentionTypeLabel($operational),
            $this->attentionReasonLabel($operational),
            $this->attentionActionLabel($operational),
            $clientContext['last_device'],
            $clientContext['os'],
            $clientContext['browser'],
            $clientContext['has_device_change'] ? 'Si' : 'No',
            $clientContext['devices_seen'],
            $appointment ? 'Si' : 'No',
            $this->appointmentStatus($cart, $appointment),
            $appointment?->id,
            $this->excelDate($appointment?->created_at),
            $this->excelTime($appointment?->created_at),
            $this->excelDate($appointment?->appointment_date),
            $this->excelTime($appointment?->appointment_date),
            $this->excelDate($appointment?->confirmed_at),
            $this->excelTime($appointment?->confirmed_at),
            $appointment?->laboratoryStore?->name,
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
                'correlation_type' => 'Relacion ambigua',
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
                'explicit' => 'Relacion explicita',
                'legacy_high' => 'Relacion historica confiable',
                'ambiguous' => 'Relacion ambigua',
                'transaction' => 'Transaccion final',
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
            'failed' => 'Fallido',
            'skipped' => 'Omitido',
            'synced' => 'Sincronizado',
            default => 'No determinada',
        };
    }

    /**
     * @return array{last_device: string, os: string, browser: string, has_device_change: bool, devices_seen: string}
     */
    private function clientContext(Cart $cart): array
    {
        $events = $cart->relationLoaded('events')
            ? $cart->events
            : $cart->events()->orderBy('occurred_at')->orderBy('id')->get();

        $clients = $events
            ->sortBy(fn ($event) => ($event->occurred_at?->format('U.u') ?? '0.000000').'-'.str_pad((string) $event->id, 20, '0', STR_PAD_LEFT))
            ->map(fn ($event) => $this->safeClientContext(is_array($event->metadata) ? ($event->metadata['client'] ?? null) : null))
            ->filter()
            ->values();

        if ($clients->isEmpty()) {
            return [
                'last_device' => 'No identificado',
                'os' => 'No identificado',
                'browser' => 'No identificado',
                'has_device_change' => false,
                'devices_seen' => 'Sin informacion',
            ];
        }

        $devicesSeen = $clients
            ->pluck('device_type')
            ->filter()
            ->unique()
            ->values();
        $last = $clients->last();

        return [
            'last_device' => $last['device_label'],
            'os' => $last['os'],
            'browser' => $last['browser'],
            'has_device_change' => $devicesSeen->count() > 1,
            'devices_seen' => $devicesSeen
                ->map(fn (string $deviceType) => $this->deviceTypeLabel($deviceType))
                ->implode(' -> '),
        ];
    }

    /**
     * @return array{device_type: string, device_label: string, browser: string, os: string}|null
     */
    private function safeClientContext(mixed $client): ?array
    {
        if (! is_array($client)) {
            return null;
        }

        $deviceType = (string) ($client['device_type'] ?? '');
        if (! in_array($deviceType, ['mobile', 'tablet', 'desktop', 'unknown'], true)) {
            $deviceType = 'unknown';
        }

        $browser = trim((string) ($client['browser'] ?? ''));
        $os = trim((string) ($client['os'] ?? ''));

        return [
            'device_type' => $deviceType,
            'device_label' => $this->deviceTypeLabel($deviceType),
            'browser' => $browser !== '' ? mb_substr($browser, 0, 64) : 'No identificado',
            'os' => $os !== '' ? mb_substr($os, 0, 64) : 'No identificado',
        ];
    }

    private function deviceTypeLabel(?string $deviceType): string
    {
        return match ($deviceType) {
            'mobile' => 'Movil',
            'tablet' => 'Tablet',
            'desktop' => 'Desktop',
            default => 'No identificado',
        };
    }

    /**
     * @param  array{requires_attention: bool, category: string, reason: string, label: string, recommended_action: string, tone: string}  $operational
     */
    private function attentionTypeLabel(array $operational): string
    {
        return match ($operational['reason'] ?? null) {
            'payment_error' => 'Error tecnico de pago',
            'payment_declined' => 'Pago rechazado',
            'callback_requested' => 'Solicitud de llamada',
            'appointment_confirmed_without_payment' => 'Cita confirmada sin pago',
            'appointment_pending' => 'Cita pendiente de confirmacion',
            'phone_call_intent' => 'Intento llamar',
            'payment_pending' => 'Pago pendiente',
            'abandoned' => 'Carrito abandonado',
            'none' => 'Sin atencion requerida',
            default => $this->shortText($operational['label'] ?? 'Sin atencion requerida', 120),
        };
    }

    /**
     * @param  array{requires_attention: bool, category: string, reason: string, label: string, recommended_action: string, tone: string}  $operational
     */
    private function attentionReasonLabel(array $operational): string
    {
        return match ($operational['reason'] ?? null) {
            'payment_error' => 'Ultimo intento de pago con error tecnico',
            'payment_declined' => 'Ultimo intento de pago rechazado',
            'callback_requested' => 'Paciente solicito llamada',
            'appointment_confirmed_without_payment' => 'Cita confirmada sin pago registrado',
            'appointment_pending' => 'Cita pendiente de confirmacion',
            'phone_call_intent' => 'Paciente intento llamar',
            'payment_pending' => 'Pago pendiente de confirmacion',
            'abandoned' => 'Carrito sin actividad reciente',
            'none' => 'Sin atencion requerida',
            default => $this->shortText($operational['label'] ?? 'Sin atencion requerida', 180),
        };
    }

    /**
     * @param  array{requires_attention: bool, category: string, reason: string, label: string, recommended_action: string, tone: string}  $operational
     */
    private function attentionActionLabel(array $operational): string
    {
        return match ($operational['reason'] ?? null) {
            'payment_error' => 'Revisar incidente de pago',
            'payment_declined' => 'Contactar al paciente para apoyar con el pago',
            'callback_requested' => 'Llamar al paciente',
            'appointment_confirmed_without_payment' => 'Recordar completar el pago',
            'appointment_pending' => 'Dar seguimiento a la confirmacion',
            'phone_call_intent' => 'Dar seguimiento al contacto',
            'payment_pending' => 'Revisar estado antes de contactar',
            default => $this->shortText($operational['recommended_action'] ?? 'Sin accion inmediata', 180),
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
        $brands = collect($cart->labBrands())->filter();

        if ($brands->count() > 1) {
            return 'Inconsistencia: multiples marcas';
        }

        return $brands->pluck('label')->filter()->implode(', ') ?: null;
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
        return $this->excelSerial($date);
    }

    private function excelTime($date): ?float
    {
        return $this->excelSerial($date);
    }

    private function excelSerial($date): ?float
    {
        if (! $date) {
            return null;
        }

        $local = Carbon::parse($date)->setTimezone('America/Monterrey');

        return Date::dateTimeToExcel(new \DateTimeImmutable($local->format('Y-m-d H:i:s')));
    }

    protected function freezePaneCell(): string
    {
        return 'D2';
    }

    protected function wrappedColumns(): array
    {
        return [
            'I' => 45,
            'AD' => 24,
            'AU' => 45,
            'BA' => 36,
        ];
    }
}

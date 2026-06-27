<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\EfevooToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryTest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('view carts') || abort(403);

        $filters = collect($request->only([
            'search',
            'type',
            'display_status',
            'start_date',
            'end_date',
        ]))->filter(fn ($v) => $v !== null && $v !== '')->all();

        $start = ! empty($filters['start_date'])
            ? Carbon::parse($filters['start_date'], 'America/Monterrey')->startOfDay()->utc()
            : null;
        $end = ! empty($filters['end_date'])
            ? Carbon::parse($filters['end_date'], 'America/Monterrey')->endOfDay()->utc()
            : null;

        $query = Cart::query()
            ->with([
                'items',
                'user.customer.laboratoryCartItems.laboratoryTest',
                'user.customer.laboratoryAppointments',
                'user.customer.laboratoryCheckoutDrafts.contact',
                'user.customer.laboratoryCheckoutDrafts.address',
            ])
            ->withCount('items')
            ->adminMonitoringFilter($filters, $start, $end)
            ->orderByDesc('updated_at');

        $carts = $query->paginate(25)->withQueryString();

        $metricsBase = Cart::query()
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($start, fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->when($end, fn ($q, $d) => $q->where('updated_at', '<=', $d));

        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $metrics = [
            'active' => (clone $metricsBase)
                ->where('status', MonitoringCartStatus::Active)
                ->where('updated_at', '>=', $staleBefore)
                ->count(),
            'abandoned' => (clone $metricsBase)
                ->where('status', MonitoringCartStatus::Active)
                ->where('updated_at', '<', $staleBefore)
                ->count(),
            'completed' => (clone $metricsBase)->where('status', MonitoringCartStatus::Completed)->count(),
            'appointment_pending_confirmation' => (clone $metricsBase)
                ->appointmentPendingConfirmation()
                ->count(),
            'appointment_confirmed_pending_payment' => (clone $metricsBase)
                ->appointmentConfirmedPendingPayment()
                ->count(),
        ];

        $den = $metrics['completed'] + $metrics['abandoned'];
        $metrics['conversion_percent'] = $den > 0
            ? round(100 * $metrics['completed'] / $den, 1)
            : null;

        $carts->getCollection()->transform(fn (Cart $cart) => $this->serializeCartRow($cart));

        return Inertia::render('Admin/Carts', [
            'carts' => $carts,
            'filters' => $filters,
            'metrics' => $metrics,
            'canViewCartDetails' => $request->user()->administrator->hasPermissionTo('view cart details'),
            'canExport' => $request->user()->administrator->hasPermissionTo('view carts'),
        ]);
    }

    public function show(Request $request, Cart $cart)
    {
        $request->user()->administrator->hasPermissionTo('view cart details') || abort(403);

        $cart->load([
            'items',
            'user.customer.laboratoryAppointments',
            'user.customer.laboratoryCheckoutDrafts.contact',
            'user.customer.laboratoryCheckoutDrafts.address',
        ]);

        return Inertia::render('Admin/CartShow', [
            'cart' => $this->serializeCartDetail($cart),
        ]);
    }

    private function serializeCartRow(Cart $cart): array
    {
        $display = $cart->displayStatus();

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
            ] : null,
            'type' => $cart->type->value,
            'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            'lab_brands' => $cart->labBrands(),
            'appointment_pending_confirmation' => $cart->hasAppointmentPendingConfirmation(),
            'appointment_confirmed_pending_payment' => $cart->hasAppointmentConfirmedPendingPayment(),
            'items_count' => $cart->items_count ?? $cart->items->count(),
            'total' => (string) $cart->total,
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $display,
            'updated_at' => $cart->updated_at?->toIso8601String(),
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
            'checkout_summary' => $cart->type === MonitoringCartType::Lab
                ? $this->serializeCheckoutSummaryForRow($cart)
                : null,
        ];
    }

    private function serializeCartDetail(Cart $cart): array
    {
        $isLab = $cart->type === MonitoringCartType::Lab;
        $testsById = collect();

        if ($isLab) {
            $testIds = $cart->items->pluck('product_id')->filter()->unique()->values();
            if ($testIds->isNotEmpty()) {
                $testsById = LaboratoryTest::query()
                    ->whereIn('id', $testIds)
                    ->get()
                    ->keyBy(fn (LaboratoryTest $test) => (string) $test->id);
            }
        }

        $items = $cart->items->map(function ($row) use ($isLab, $testsById) {
            $test = $testsById->get((string) $row->product_id);

            return [
                'id' => $row->id,
                'name' => $row->name,
                'quantity' => $row->quantity,
                'unit_price' => (string) $row->price,
                'unit_price_formatted' => formattedPrice((float) $row->price),
                'line_total' => (string) round((float) $row->price * (int) $row->quantity, 2),
                'line_total_formatted' => formattedPrice(round((float) $row->price * (int) $row->quantity, 2)),
                'brand_label' => $test?->brand?->label(),
                'requires_appointment' => (bool) ($test?->requires_appointment ?? false),
            ];
        });

        $purchase = $isLab ? $cart->relatedLaboratoryPurchase() : null;

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
                'phone' => $cart->user->full_phone,
                'admin_url' => route('admin.users.show', $cart->user),
            ] : null,
            'type' => $cart->type->value,
            'type_label' => $isLab ? 'Laboratorio' : 'Farmacia',
            'lab_brands' => $cart->labBrands(),
            'total' => (string) $cart->total,
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $cart->displayStatus(),
            'monitoring_status' => $cart->status->value,
            'monitoring_status_label' => $cart->status === MonitoringCartStatus::Completed ? 'Completado en monitoreo' : 'Activo en monitoreo',
            'appointment_pending_confirmation' => $cart->hasAppointmentPendingConfirmation(),
            'appointment_confirmed_pending_payment' => $cart->hasAppointmentConfirmedPendingPayment(),
            'items_count' => $cart->items->count(),
            'items' => $items,
            'created_at_human' => $cart->created_at?->format('d/m/Y H:i'),
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
            'completed_at_human' => $cart->completed_at?->format('d/m/Y H:i'),
            'abandoned_threshold_minutes' => Cart::ABANDONED_AFTER_MINUTES,
            'related_laboratory_purchase' => $purchase ? [
                'id' => $purchase->id,
                'brand_label' => $purchase->brand->label(),
                'created_at_human' => $purchase->created_at?->format('d/m/Y H:i'),
                'total_formatted' => $purchase->formatted_total ?? formattedPrice((float) $purchase->total),
                'admin_url' => route('admin.laboratory-purchases.show', $purchase),
            ] : null,
            'laboratory_appointments' => $isLab
                ? $cart->laboratoryAppointmentsForDisplay()->map(
                    fn (LaboratoryAppointment $appointment) => $this->serializeLaboratoryAppointmentForAdmin($appointment),
                )->values()->all()
                : [],
            'checkout_drafts' => $isLab
                ? $this->serializeCheckoutDrafts($cart)
                : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCheckoutDrafts(Cart $cart): array
    {
        return $this->mapCheckoutDrafts($cart, detailed: true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCheckoutSummaryForRow(Cart $cart): array
    {
        return $this->mapCheckoutDrafts($cart, detailed: false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapCheckoutDrafts(Cart $cart, bool $detailed): array
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return [];
        }

        $brandValues = collect($cart->labBrands())->pluck('value')->filter()->values();

        $drafts = $customer->relationLoaded('laboratoryCheckoutDrafts')
            ? $customer->laboratoryCheckoutDrafts
            : $customer->laboratoryCheckoutDrafts()->with(['contact', 'address'])->get();

        return $drafts
            ->when(
                $brandValues->isNotEmpty(),
                fn ($rows) => $rows->filter(
                    fn (LaboratoryCheckoutDraft $draft) => $brandValues->contains($draft->laboratory_brand->value),
                ),
            )
            ->sortByDesc('updated_at')
            ->map(function (LaboratoryCheckoutDraft $draft) use ($customer, $cart, $detailed) {
                $appointment = $this->appointmentForBrand($cart, $draft->laboratory_brand);
                $appointmentData = $appointment
                    ? $this->serializeLaboratoryAppointmentForAdmin($appointment, compact: ! $detailed)
                    : null;

                $entry = [
                    'id' => $draft->id,
                    'brand_label' => $draft->laboratory_brand->label(),
                    'checkout_step' => $draft->checkout_step,
                    'checkout_step_label' => $this->checkoutStepLabel($draft->checkout_step),
                    'patient_name' => $draft->contact?->full_name,
                    'address_short' => $this->shortAddressLabel($draft->address),
                    'payment_method_label' => $this->paymentMethodLabel(
                        $draft->payment_method,
                        $customer,
                    ),
                    'appointment' => $appointmentData,
                ];

                if ($detailed) {
                    $entry['updated_at_human'] = $draft->updated_at?->format('d/m/Y H:i');
                    $entry['patient'] = $draft->contact ? [
                        'full_name' => $draft->contact->full_name,
                        'phone' => $draft->contact->phone_for_display ?? $draft->contact->phone,
                        'formatted_birth_date' => $draft->contact->formatted_birth_date,
                        'formatted_gender' => $draft->contact->formatted_gender,
                    ] : null;
                    $entry['address'] = $draft->address ? [
                        'formatted_address' => $draft->address->formatted_address,
                        'full_address' => $draft->address->full_address,
                    ] : null;
                }

                return $entry;
            })
            ->values()
            ->all();
    }

    private function shortAddressLabel(?\App\Models\Address $address): ?string
    {
        if (! $address) {
            return null;
        }

        $text = trim((string) ($address->formatted_address ?: $address->full_address));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > 48
            ? mb_substr($text, 0, 45).'…'
            : $text;
    }

    private function appointmentForBrand(Cart $cart, \App\Enums\LaboratoryBrand $brand): ?LaboratoryAppointment
    {
        $customer = $cart->user?->customer;
        if (! $customer) {
            return null;
        }

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        return $appointments
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->brand === $brand)
            ->sortByDesc('updated_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLaboratoryAppointmentForAdmin(
        LaboratoryAppointment $appointment,
        bool $compact = false,
    ): array {
        $data = [
            'id' => $appointment->id,
            'brand_label' => $appointment->brand->label(),
            'patient_name' => $appointment->patient_full_name,
            'is_confirmed' => $appointment->confirmed_at !== null,
            'confirmed_at_human' => $appointment->confirmed_at?->format('d/m/Y H:i'),
            'appointment_date_human' => $appointment->formatted_appointment_date,
            'has_linked_purchase' => $appointment->laboratory_purchase_id !== null,
            'request_saved_at' => $appointment->formatted_request_saved_at,
            'callback_availability_range' => $appointment->formatted_callback_availability_range,
            'callback_comment' => filled($appointment->patient_callback_comment)
                ? $appointment->patient_callback_comment
                : null,
            'callback_comment_short' => $this->shortText($appointment->patient_callback_comment, 60),
            'has_callback_info' => (bool) $appointment->has_left_callback_info,
            'has_phone_call_intent' => $appointment->phone_call_intent_at !== null,
            'phone_call_intent_at_human' => $appointment->formatted_phone_call_intent_at,
            'updated_at_human' => $appointment->updated_at?->format('d/m/Y H:i'),
            'admin_url' => route('admin.laboratory-appointments.show', $appointment),
        ];

        if ($compact) {
            return collect($data)->only([
                'request_saved_at',
                'callback_availability_range',
                'callback_comment_short',
                'has_callback_info',
                'has_phone_call_intent',
                'phone_call_intent_at_human',
            ])->filter(fn ($value) => $value !== null && $value !== false)->all();
        }

        return $data;
    }

    private function shortText(?string $text, int $maxLength): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $maxLength
            ? mb_substr($text, 0, $maxLength - 1).'…'
            : $text;
    }

    private function checkoutStepLabel(string $step): string
    {
        return match ($step) {
            'patient' => 'Paciente',
            'address' => 'Dirección',
            'payment' => 'Método de pago',
            'appointment' => 'Cita',
            'confirmation' => 'Confirmación',
            default => $step,
        };
    }

    private function paymentMethodLabel(?string $paymentMethod, \App\Models\Customer $customer): ?string
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return null;
        }

        return match ($paymentMethod) {
            'odessa' => 'Saldo a la Vista (Odessa)',
            'paypal' => 'PayPal',
            'coupon_balance' => 'Crédito a favor (cupón)',
            default => $this->efevooTokenPaymentLabel($paymentMethod, $customer),
        };
    }

    private function efevooTokenPaymentLabel(string $paymentMethod, \App\Models\Customer $customer): string
    {
        if (! ctype_digit($paymentMethod)) {
            return $paymentMethod;
        }

        $token = EfevooToken::query()
            ->where('customer_id', $customer->id)
            ->where('id', (int) $paymentMethod)
            ->first();

        if (! $token) {
            return 'Tarjeta #'.$paymentMethod;
        }

        return sprintf(
            '%s •••• %s',
            ucfirst(strtolower((string) $token->card_brand)),
            $token->card_last_four,
        );
    }
}

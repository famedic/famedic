<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Services\Carts\CartUserActivityResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Cart extends Model
{
    public const ABANDONED_AFTER_MINUTES = 30;

    public static function abandonedAfterMinutes(): int
    {
        return (int) config('carts.abandoned_after_minutes', self::ABANDONED_AFTER_MINUTES);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => MonitoringCartType::class,
            'status' => MonitoringCartStatus::class,
            'total' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function laboratoryPurchases(): HasMany
    {
        return $this->hasMany(LaboratoryPurchase::class);
    }

    public function laboratoryAppointments(): HasMany
    {
        return $this->hasMany(LaboratoryAppointment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CartEvent::class);
    }

    /**
     * Última actividad real del usuario (cart_events), no carts.updated_at.
     */
    public function lastUserActivityAt(): Carbon
    {
        if (! Schema::hasTable('cart_events')) {
            return $this->updated_at?->copy() ?? $this->created_at?->copy() ?? now();
        }

        return app(CartUserActivityResolver::class)->lastUserActivityAt($this);
    }

    /**
     * Activo en carrito, abandonado (sin actividad), vacío (histórico) o comprado.
     */
    public function displayStatus(): string
    {
        if ($this->status === MonitoringCartStatus::Completed) {
            return 'completed';
        }

        if ($this->isEmptyActiveMonitoringCart()) {
            return 'empty';
        }

        if ($this->hasAppointmentPendingConfirmation()) {
            return 'active';
        }

        if ($this->lastUserActivityAt()->lt(now()->subMinutes(self::abandonedAfterMinutes()))) {
            return 'abandoned';
        }

        return 'active';
    }

    public function displayStatusLabel(): string
    {
        return match ($this->displayStatus()) {
            'completed' => 'Comprado',
            'abandoned' => 'Abandonado',
            'empty' => 'Vacío (histórico)',
            default => 'Activo',
        };
    }

    public function isEmptyActiveMonitoringCart(): bool
    {
        if ($this->status !== MonitoringCartStatus::Active) {
            return false;
        }

        if ($this->relationLoaded('items')) {
            return $this->items->isEmpty();
        }

        if ($this->relationLoaded('items_count')) {
            return (int) $this->items_count === 0;
        }

        return ! $this->items()->exists();
    }

    /**
     * Carritos visibles en listados operativos, KPIs y dashboards.
     * Excluye activos vaciados intencionalmente para conservar trazabilidad.
     */
    public function scopeOperationalMonitoring(Builder $query): void
    {
        $query->where(function (Builder $inner) {
            $inner->where('status', MonitoringCartStatus::Completed->value)
                ->orWhereHas('items');
        });
    }

    /**
     * Minutos desde la última actividad real del usuario. Null si ya está comprado.
     */
    public function inactiveForMinutes(): ?int
    {
        if ($this->status === MonitoringCartStatus::Completed) {
            return null;
        }

        $lastActivity = $this->lastUserActivityAt();
        $seconds = max(0, now()->getTimestamp() - $lastActivity->getTimestamp());

        return (int) floor($seconds / 60);
    }

    /**
     * Etiqueta legible del tiempo sin actividad (ej. "45 min", "2 h 10 min").
     */
    public function inactiveForLabel(): ?string
    {
        $minutes = $this->inactiveForMinutes();

        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($hours < 24) {
            return $remainder > 0
                ? "{$hours} h {$remainder} min"
                : "{$hours} h";
        }

        $days = intdiv($hours, 24);
        $hourRemainder = $hours % 24;

        return $hourRemainder > 0
            ? "{$days} d {$hourRemainder} h"
            : "{$days} d";
    }

    /**
     * Momento estimado en que el carrito cruzó el umbral de abandono.
     */
    public function abandonedAt(): ?Carbon
    {
        if ($this->displayStatus() !== 'abandoned') {
            return null;
        }

        return $this->lastUserActivityAt()->copy()->addMinutes(self::abandonedAfterMinutes());
    }

    public function appointmentExportStatus(): string
    {
        if ($this->type !== MonitoringCartType::Lab || ! $this->requiresAppointmentForExport()) {
            return 'No aplica';
        }

        if ($this->hasRelatedLaboratoryAppointment()) {
            return 'Con cita';
        }

        return 'Sin cita';
    }

    public function scopeAdminMonitoringFilter(Builder $query, array $filters, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->whereHas('user', function (Builder $uq) use ($search) {
                    $uq->where(function (Builder $inner) use ($search) {
                        $inner->where('name', 'like', '%'.$search.'%')
                            ->orWhere('paternal_lastname', 'like', '%'.$search.'%')
                            ->orWhere('maternal_lastname', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%');
                    });
                });
            })
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['display_status'] ?? null, fn (Builder $q, string $status) => $q->displayStatusFilter($status))
            ->when($filters['operational_filter'] ?? null, fn (Builder $q, string $filter) => $q->operationalFilter($filter))
            ->when($filters['payment_status'] ?? null, fn (Builder $q, string $status) => $q->relatedPaymentAttemptStatus($status))
            ->when($filters['operational_bucket'] ?? null, fn (Builder $q, string $bucket) => $q->operationalBucket($bucket))
            ->when($filters['checkout_stage'] ?? null, fn (Builder $q, string $stage) => $q->checkoutStageFilter($stage))
            ->when($filters['appointment_filter'] ?? null, fn (Builder $q, string $filter) => $q->appointmentFilter($filter))
            ->when($filters['contact_filter'] ?? null, fn (Builder $q, string $filter) => $q->contactFilter($filter))
            ->when($filters['customer_segment'] ?? null, fn (Builder $q, string $segment) => $q->customerSegmentFilter($segment))
            ->when($filters['brand'] ?? null, fn (Builder $q, string $brand) => $q->labBrandFilter($brand))
            ->when($filters['amount_range'] ?? null, fn (Builder $q, string $range) => $q->amountRangeFilter($range))
            ->when($filters['inactivity_range'] ?? null, fn (Builder $q, string $range) => $q->inactivityRangeFilter($range))
            ->when($start, fn (Builder $q, Carbon $d) => $q->where('updated_at', '>=', $d))
            ->when($end, fn (Builder $q, Carbon $d) => $q->where('updated_at', '<=', $d));
    }

    public function scopeDisplayStatusFilter($query, string $status): void
    {
        $activitySql = CartUserActivityResolver::lastActivityAtSql();
        $staleThreshold = now()->subMinutes(self::abandonedAfterMinutes());

        if ($status === 'completed') {
            $query->where('status', MonitoringCartStatus::Completed->value);
        } elseif ($status === 'abandoned') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->whereHas('items')
                ->whereRaw("{$activitySql} < ?", [$staleThreshold])
                ->where(fn (Builder $q) => $q->whereNot(fn (Builder $inner) => $inner->appointmentPendingConfirmation()));
        } elseif ($status === 'active') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->whereHas('items')
                ->whereRaw("{$activitySql} >= ?", [$staleThreshold]);
        } elseif ($status === 'empty') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->whereDoesntHave('items');
        }
    }

    public function scopeOperationalFilter(Builder $query, string $filter): void
    {
        if ($filter === 'appointment_pending') {
            $query->appointmentPendingConfirmation();
        } elseif ($filter === 'appointment_confirmed_pending_payment') {
            $query->appointmentConfirmedPendingPayment();
        } elseif ($filter === 'callback_requested') {
            $query->where('type', MonitoringCartType::Lab)
                ->whereExists($this->appointmentCartExistsSubquery(
                    fn (QueryBuilder $appointment) => $appointment->where(function (QueryBuilder $callback) {
                        $callback->whereNotNull('la.callback_availability_starts_at')
                            ->orWhereNotNull('la.callback_availability_ends_at')
                            ->orWhere(function (QueryBuilder $comment) {
                                $comment->whereNotNull('la.patient_callback_comment')
                                    ->where('la.patient_callback_comment', '!=', '');
                            });
                    }),
                ));
        }
    }

    public function scopeRelatedPaymentAttemptStatus(Builder $query, string $status): void
    {
        $statuses = match ($status) {
            PaymentAttempt::STATUS_PENDING => [PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING],
            PaymentAttempt::STATUS_APPROVED => [PaymentAttempt::STATUS_APPROVED],
            PaymentAttempt::STATUS_DECLINED => [PaymentAttempt::STATUS_DECLINED],
            PaymentAttempt::STATUS_ERROR => [PaymentAttempt::STATUS_ERROR],
            default => [],
        };

        if ($statuses === []) {
            return;
        }

        $query->where('type', MonitoringCartType::Lab);

        if (! $this->paymentAttemptsHaveCartId()) {
            $query->whereExists($this->legacyPaymentStatusExistsSubquery($statuses));

            return;
        }

        $query->where(function (Builder $payment) use ($statuses) {
            $payment
                ->whereExists($this->explicitPaymentStatusExistsSubquery($statuses))
                ->orWhere(function (Builder $legacy) use ($statuses) {
                    $legacy
                        ->whereNotExists($this->explicitPaymentAttemptExistsSubquery())
                        ->whereExists($this->legacyPaymentStatusExistsSubquery($statuses));
                });
            });
    }

    public function scopeOperationalBucket(Builder $query, string $bucket): void
    {
        if ($bucket === 'no_progress') {
            $query->checkoutStageFilter('no_progress');
        } elseif ($bucket === 'payment') {
            $query->operationalPaymentBucket();
        } elseif ($bucket === 'appointment') {
            $query->operationalAppointmentBucket();
        } elseif ($bucket === 'contact') {
            $query->operationalContactBucket();
        } elseif ($bucket === 'attention') {
            $query->where('status', '!=', MonitoringCartStatus::Completed->value)
                ->where(function (Builder $attention) {
                    $attention
                        ->where(fn (Builder $q) => $q->operationalPaymentBucket())
                        ->orWhere(fn (Builder $q) => $q->operationalAppointmentBucket())
                        ->orWhere(fn (Builder $q) => $q->operationalContactBucket());
                });
        }
    }

    public function scopeCheckoutStageFilter(Builder $query, string $stage): void
    {
        if ($stage === 'no_progress') {
            $activitySql = CartUserActivityResolver::lastActivityAtSql();

            $query->where('type', MonitoringCartType::Lab)
                ->where('status', MonitoringCartStatus::Active)
                ->whereRaw("{$activitySql} < ?", [now()->subMinutes(self::abandonedAfterMinutes())])
                ->whereNotExists($this->checkoutDraftExistsSubquery(
                    fn (QueryBuilder $draft) => $draft->whereNotNull('lcd.contact_id'),
                ));

            return;
        }

        if ($stage === 'patient') {
            $query->whereExists($this->checkoutDraftExistsSubquery(
                fn (QueryBuilder $draft) => $draft->whereNotNull('lcd.contact_id'),
            ));
        } elseif ($stage === 'address') {
            $query->whereExists($this->checkoutDraftExistsSubquery(
                fn (QueryBuilder $draft) => $draft->whereNotNull('lcd.address_id'),
            ));
        } elseif ($stage === 'payment') {
            $query->whereExists($this->checkoutDraftExistsSubquery(
                fn (QueryBuilder $draft) => $draft->whereNotNull('lcd.payment_method'),
            ));
        } elseif ($stage === 'appointment') {
            $query->whereExists($this->appointmentCartExistsSubquery(fn (QueryBuilder $appointment) => $appointment));
        } elseif ($stage === 'confirmation') {
            $query->whereExists($this->checkoutDraftExistsSubquery(
                fn (QueryBuilder $draft) => $draft->where('lcd.checkout_step', 'confirmation'),
            ));
        } elseif ($stage === 'completed') {
            $query->where('status', MonitoringCartStatus::Completed);
        }
    }

    public function scopeAppointmentFilter(Builder $query, string $filter): void
    {
        if ($filter === 'none') {
            $query->where('type', MonitoringCartType::Lab)
                ->whereNotExists($this->appointmentCartExistsSubquery(fn (QueryBuilder $appointment) => $appointment));
        } elseif ($filter === 'pending') {
            $query->appointmentPendingConfirmation();
        } elseif ($filter === 'confirmed') {
            $query->whereExists($this->appointmentCartExistsSubquery(
                fn (QueryBuilder $appointment) => $appointment->whereNotNull('la.confirmed_at'),
            ));
        } elseif ($filter === 'confirmed_without_payment') {
            $query->appointmentConfirmedPendingPayment();
        }
    }

    public function scopeContactFilter(Builder $query, string $filter): void
    {
        if ($filter === 'callback_requested') {
            $query->whereExists($this->appointmentCartExistsSubquery(
                fn (QueryBuilder $appointment) => $appointment->where(function (QueryBuilder $callback) {
                    $callback->whereNotNull('la.callback_availability_starts_at')
                        ->orWhereNotNull('la.callback_availability_ends_at')
                        ->orWhere(function (QueryBuilder $comment) {
                            $comment->whereNotNull('la.patient_callback_comment')
                                ->where('la.patient_callback_comment', '!=', '');
                        });
                }),
            ));
        } elseif ($filter === 'phone_call_intent') {
            $query->whereExists($this->appointmentCartExistsSubquery(
                fn (QueryBuilder $appointment) => $appointment->whereNotNull('la.phone_call_intent_at'),
            ));
        }
    }

    public function scopeCustomerSegmentFilter(Builder $query, string $segment): void
    {
        $query->whereHas('user.customer', function (Builder $customer) use ($segment) {
            $customer->where(function (Builder $inner) use ($segment) {
                $purchaseCountSql = '(SELECT COUNT(*) FROM laboratory_purchases lp WHERE lp.customer_id = customers.id AND lp.deleted_at IS NULL AND lp.created_at < carts.created_at)
                    + (SELECT COUNT(*) FROM online_pharmacy_purchases opp WHERE opp.customer_id = customers.id AND opp.deleted_at IS NULL AND opp.created_at < carts.created_at)';

                if ($segment === 'new') {
                    $inner->whereRaw("{$purchaseCountSql} = 0");
                } elseif ($segment === 'existing') {
                    $inner->whereRaw("{$purchaseCountSql} = 1");
                } elseif ($segment === 'recurrent') {
                    $inner->whereRaw("{$purchaseCountSql} >= 2");
                }
            });
        });
    }

    public function scopeLabBrandFilter(Builder $query, string $brand): void
    {
        $query->where('type', MonitoringCartType::Lab);

        if ($brand === 'unknown') {
            $query->whereNotExists($this->cartItemBrandExistsSubquery());

            return;
        }

        if (! LaboratoryBrand::tryFrom($brand)) {
            return;
        }

        $query->whereExists($this->cartItemBrandExistsSubquery($brand));
    }

    public function scopeAmountRangeFilter(Builder $query, string $range): void
    {
        match ($range) {
            'lt_1000' => $query->where('total', '<', 1000),
            '1000_2000' => $query->whereBetween('total', [1000, 2000]),
            '2000_5000' => $query->whereBetween('total', [2000, 5000]),
            'gt_5000' => $query->where('total', '>', 5000),
            default => null,
        };
    }

    public function scopeInactivityRangeFilter(Builder $query, string $range): void
    {
        $activitySql = CartUserActivityResolver::lastActivityAtSql();

        $query->where('status', '!=', MonitoringCartStatus::Completed->value);

        match ($range) {
            'lt_1h' => $query->whereRaw("{$activitySql} >= ?", [now()->subHour()]),
            '1_3h' => $query->whereRaw("{$activitySql} BETWEEN ? AND ?", [now()->subHours(3), now()->subHour()]),
            '3_24h' => $query->whereRaw("{$activitySql} BETWEEN ? AND ?", [now()->subDay(), now()->subHours(3)]),
            '1_3d' => $query->whereRaw("{$activitySql} BETWEEN ? AND ?", [now()->subDays(3), now()->subDay()]),
            'gt_3d' => $query->whereRaw("{$activitySql} < ?", [now()->subDays(3)]),
            default => null,
        };
    }

    public function scopeOperationalPaymentBucket(Builder $query): void
    {
        $query->where('status', '!=', MonitoringCartStatus::Completed->value)
            ->where(fn (Builder $q) => $q->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_ERROR)
                ->orWhere(fn (Builder $inner) => $inner->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_DECLINED))
                ->orWhere(fn (Builder $inner) => $inner->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_PENDING)));
    }

    public function scopeOperationalAppointmentBucket(Builder $query): void
    {
        $query->where('status', '!=', MonitoringCartStatus::Completed->value)
            ->where(function (Builder $appointments) {
                $appointments
                    ->where(fn (Builder $q) => $q->appointmentPendingConfirmation())
                    ->orWhere(fn (Builder $q) => $q->appointmentConfirmedPendingPayment());
            });
    }

    public function scopeOperationalContactBucket(Builder $query): void
    {
        $query->where('type', MonitoringCartType::Lab)
            ->where('status', '!=', MonitoringCartStatus::Completed->value)
            ->where(function (Builder $contact) {
                $contact
                    ->whereExists($this->appointmentCartExistsSubquery(
                        fn (QueryBuilder $appointment) => $appointment->where(function (QueryBuilder $callback) {
                            $callback->whereNotNull('la.callback_availability_starts_at')
                                ->orWhereNotNull('la.callback_availability_ends_at')
                                ->orWhere(function (QueryBuilder $comment) {
                                    $comment->whereNotNull('la.patient_callback_comment')
                                        ->where('la.patient_callback_comment', '!=', '');
                                });
                        }),
                    ))
                    ->orWhereExists($this->appointmentCartExistsSubquery(
                        fn (QueryBuilder $appointment) => $appointment->whereNotNull('la.phone_call_intent_at'),
                    ));
            });
    }

    public function scopeAppointmentPendingConfirmation(Builder $query): void
    {
        $query->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereExists($this->appointmentCartExistsSubquery(
                fn (QueryBuilder $appointment) => $appointment->whereNull('la.confirmed_at'),
            ));
    }

    public function scopeAppointmentConfirmedPendingPayment(Builder $query): void
    {
        $query->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereExists($this->appointmentCartExistsSubquery(
                fn (QueryBuilder $appointment) => $appointment
                    ->whereNotNull('la.confirmed_at')
                    ->whereNull('la.laboratory_purchase_id'),
            ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function labBrands(): array
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return [];
        }

        $brands = $this->distinctLabBrandsFromCartItems();

        if ($brands->isEmpty()) {
            $customer = $this->user?->customer;
            if ($customer) {
                $brands = $this->distinctLabBrandsFromCustomer($customer);
            }
        }

        if ($brands->isEmpty() && $this->status === MonitoringCartStatus::Completed) {
            $brands = $this->distinctLabBrandsFromCompletedPurchase();
        }

        return $brands
            ->map(fn (LaboratoryBrand $brand) => [
                'value' => $brand->value,
                'label' => $brand->label(),
            ])
            ->values()
            ->all();
    }

    public function hasAppointmentPendingConfirmation(): bool
    {
        if ($this->type !== MonitoringCartType::Lab || $this->status !== MonitoringCartStatus::Active) {
            return false;
        }

        return $this->laboratoryAppointmentsForDisplay()
            ->contains(fn (LaboratoryAppointment $appointment) => $appointment->confirmed_at === null);
    }

    public function hasAppointmentConfirmedPendingPayment(): bool
    {
        if ($this->type !== MonitoringCartType::Lab || $this->status !== MonitoringCartStatus::Active) {
            return false;
        }

        return $this->laboratoryAppointmentsForDisplay()
            ->contains(fn (LaboratoryAppointment $appointment) => $appointment->confirmed_at !== null
                && $appointment->laboratory_purchase_id === null);
    }

    public function relatedLaboratoryPurchase(): ?LaboratoryPurchase
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return null;
        }

        $explicit = $this->explicitLaboratoryPurchase();

        if ($explicit) {
            return $explicit;
        }

        $customerId = $this->user?->customer?->id;
        if (! $customerId) {
            return null;
        }

        return LaboratoryPurchase::query()
            ->where('customer_id', $customerId)
            ->when(
                $this->completed_at,
                fn ($query) => $query->whereBetween('created_at', [
                    $this->completed_at->copy()->subDay(),
                    $this->completed_at->copy()->addDay(),
                ]),
            )
            ->latest()
            ->first();
    }

    /**
     * Compra ligada explícitamente a este carrito (cart_id). Sin fallback histórico del customer.
     */
    public function explicitLaboratoryPurchase(): ?LaboratoryPurchase
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return null;
        }

        return $this->relationLoaded('laboratoryPurchases')
            ? $this->laboratoryPurchases->sortByDesc('created_at')->first()
            : $this->laboratoryPurchases()->latest()->first();
    }

    /**
     * Citas ligadas explícitamente a este carrito (cart_id). Sin fallback histórico del customer.
     *
     * @return \Illuminate\Support\Collection<int, LaboratoryAppointment>
     */
    public function explicitLaboratoryAppointments(): Collection
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return collect();
        }

        $appointments = $this->relationLoaded('laboratoryAppointments')
            ? $this->laboratoryAppointments
            : $this->laboratoryAppointments()->get();

        return $appointments->sortByDesc('updated_at')->values();
    }

    /**
     * Cita de laboratorio asociada a este carrito (máximo 1).
     *
     * Prioriza la cita ligada al pedido del carrito. Excluye citas que ya
     * tienen otro pedido asignado (pertenecen a otra compra/carrito).
     *
     * @return \Illuminate\Support\Collection<int, LaboratoryAppointment>
     */
    public function laboratoryAppointmentsForDisplay(): Collection
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return collect();
        }

        $customer = $this->user?->customer;
        if (! $customer) {
            return collect();
        }

        $explicitAppointments = $this->relationLoaded('laboratoryAppointments')
            ? $this->laboratoryAppointments
            : $this->laboratoryAppointments()->get();

        if ($explicitAppointments->isNotEmpty()) {
            return $explicitAppointments->sortByDesc('updated_at')->values();
        }

        $brandValues = collect($this->labBrands())->pluck('value')->filter()->values();

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        $candidates = $appointments
            ->when(
                $brandValues->isNotEmpty(),
                fn (Collection $rows) => $rows->filter(
                    fn (LaboratoryAppointment $appointment) => $brandValues->contains($appointment->brand->value),
                ),
            )
            ->filter(fn (LaboratoryAppointment $appointment) => $appointment->cart_id === null)
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $purchase = $this->relatedLaboratoryPurchase();

        if ($purchase) {
            $linkedToPurchase = $candidates->first(
                fn (LaboratoryAppointment $appointment) => (int) $appointment->laboratory_purchase_id === (int) $purchase->id,
            );

            if ($linkedToPurchase) {
                return collect([$linkedToPurchase]);
            }
        }

        // Sin pedido de este carrito: solo citas abiertas (sin pedido de otra compra).
        $eligible = $candidates->filter(
            fn (LaboratoryAppointment $appointment) => $appointment->laboratory_purchase_id === null
                || ($purchase !== null && (int) $appointment->laboratory_purchase_id === (int) $purchase->id),
        );

        if ($eligible->isEmpty()) {
            return collect();
        }

        $windowStart = $this->created_at?->copy();
        $windowEnd = ($this->completed_at ?? $this->updated_at)?->copy()?->addDay();

        if ($windowStart && $windowEnd) {
            $inWindow = $eligible->filter(
                fn (LaboratoryAppointment $appointment) => $appointment->updated_at
                    && $appointment->updated_at->between($windowStart, $windowEnd),
            );

            if ($inWindow->isNotEmpty()) {
                return collect([$inWindow->sortByDesc('updated_at')->first()]);
            }
        }

        if ($this->status === MonitoringCartStatus::Completed) {
            if (! $this->completed_at) {
                return collect();
            }

            $nearest = $eligible
                ->sortBy(fn (LaboratoryAppointment $appointment) => abs(
                    ($appointment->updated_at?->timestamp ?? 0) - $this->completed_at->timestamp
                ))
                ->first();

            if (
                $nearest?->updated_at
                && abs($nearest->updated_at->diffInHours($this->completed_at)) <= 48
            ) {
                return collect([$nearest]);
            }

            return collect();
        }

        return collect([$eligible->sortByDesc('updated_at')->first()]);
    }

    private function appointmentCartExistsSubquery(callable $appointmentConstraint): \Closure
    {
        return function ($sub) use ($appointmentConstraint) {
            $sub->selectRaw('1')
                ->from('laboratory_appointments as la')
                ->whereNull('la.deleted_at')
                ->where(function ($match) {
                    if ($this->laboratoryAppointmentsHaveCartId()) {
                        $match
                            ->whereColumn('la.cart_id', 'carts.id')
                            ->orWhere(function ($legacy) {
                                $legacy
                                    ->whereNull('la.cart_id')
                                    ->whereExists($this->legacyAppointmentCartMatchSubquery());
                            });

                        return;
                    }

                    $match->whereExists($this->legacyAppointmentCartMatchSubquery());
                });

            $appointmentConstraint($sub);
        };
    }

    private function legacyAppointmentCartMatchSubquery(): \Closure
    {
        return function ($sub) {
            $sub->selectRaw('1')
                ->from('customers as c')
                ->whereColumn('c.user_id', 'carts.user_id')
                ->whereColumn('la.customer_id', 'c.id')
                ->whereNull('la.cart_id')
                ->whereExists(function ($brandSub) {
                    $brandSub->selectRaw('1')
                        ->from('cart_items as ci_appointment_brand')
                        ->join('laboratory_tests as lt_appointment_brand', function ($join) {
                            $join->whereRaw('lt_appointment_brand.id = ci_appointment_brand.product_id');
                        })
                        ->whereColumn('ci_appointment_brand.cart_id', 'carts.id')
                        ->whereColumn('lt_appointment_brand.brand', 'la.brand');
                });
        };
    }

    private function checkoutDraftExistsSubquery(callable $draftConstraint): \Closure
    {
        return function ($sub) use ($draftConstraint) {
            $sub->selectRaw('1')
                ->from('customers as cd_customer')
                ->join('laboratory_checkout_drafts as lcd', 'lcd.customer_id', '=', 'cd_customer.id')
                ->whereColumn('cd_customer.user_id', 'carts.user_id')
                ->where(function ($brandMatch) {
                    $brandMatch
                        ->whereExists(function ($brandSub) {
                            $brandSub->selectRaw('1')
                                ->from('cart_items as ci_brand')
                                ->join('laboratory_tests as lt_brand', function ($join) {
                                    $join->whereRaw('lt_brand.id = ci_brand.product_id');
                                })
                                ->whereColumn('ci_brand.cart_id', 'carts.id')
                                ->whereColumn('lt_brand.brand', 'lcd.laboratory_brand');
                        })
                        ->orWhereNotExists($this->cartItemBrandExistsSubquery());
                });

            $draftConstraint($sub);
        };
    }

    private function cartItemBrandExistsSubquery(?string $brand = null): \Closure
    {
        return function ($sub) use ($brand) {
            $sub->selectRaw('1')
                ->from('cart_items as ci')
                ->join('laboratory_tests as lt', function ($join) {
                    $join->whereRaw('lt.id = ci.product_id');
                })
                ->whereColumn('ci.cart_id', 'carts.id')
                ->when($brand !== null, fn ($query) => $query->where('lt.brand', $brand));
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function paymentAttemptWindowStartSql(string $cartAlias): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "datetime({$cartAlias}.created_at, '-5 minutes')";
        }

        if ($driver === 'pgsql') {
            return "{$cartAlias}.created_at - INTERVAL '5 minutes'";
        }

        return "DATE_SUB({$cartAlias}.created_at, INTERVAL 5 MINUTE)";
    }

    private function paymentAttemptWindowEndSql(string $cartAlias): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "datetime(COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at), '+2 hours')";
        }

        if ($driver === 'pgsql') {
            return "COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at) + INTERVAL '2 hours'";
        }

        return "DATE_ADD(COALESCE({$cartAlias}.completed_at, {$cartAlias}.updated_at), INTERVAL 2 HOUR)";
    }

    private function latestPaymentAttemptIdSql(string $cartAlias, string $customerAlias): string
    {
        $sortSql = DB::connection()->getDriverName() === 'sqlite'
            ? 'COALESCE(pa_latest.processed_at, pa_latest.updated_at, pa_latest.created_at)'
            : 'COALESCE(pa_latest.processed_at, pa_latest.updated_at, pa_latest.created_at)';

        $explicitGuard = $this->paymentAttemptsHaveCartId() ? 'AND pa_latest.cart_id IS NULL' : '';

        return "(SELECT pa_latest.id
            FROM payment_attempts pa_latest
            WHERE pa_latest.customer_id = {$customerAlias}.id
                AND pa_latest.gateway = 'efevoopay'
                {$explicitGuard}
                AND ABS(pa_latest.amount_cents - ROUND({$cartAlias}.total * 100)) <= 100
                AND pa_latest.created_at >= ".$this->paymentAttemptWindowStartSql($cartAlias)."
                AND pa_latest.created_at <= ".$this->paymentAttemptWindowEndSql($cartAlias)."
            ORDER BY {$sortSql} DESC, pa_latest.id DESC
            LIMIT 1)";
    }

    private function ambiguousPaymentAttemptExistsSubquery(string $paymentAttemptAlias): \Closure
    {
        return function ($sub) use ($paymentAttemptAlias) {
            $sub->selectRaw('1')
                ->from('carts as competing_carts')
                ->join('customers as competing_customers', 'competing_customers.user_id', '=', 'competing_carts.user_id')
                ->whereColumn('competing_carts.id', '!=', 'carts.id')
                ->whereColumn('competing_customers.id', "{$paymentAttemptAlias}.customer_id")
                ->whereRaw("ABS({$paymentAttemptAlias}.amount_cents - ROUND(competing_carts.total * 100)) <= 100")
                ->whereRaw("{$paymentAttemptAlias}.created_at >= ".$this->paymentAttemptWindowStartSql('competing_carts'))
                ->whereRaw("{$paymentAttemptAlias}.created_at <= ".$this->paymentAttemptWindowEndSql('competing_carts'));
        };
    }

    /**
     * @param  list<string>  $statuses
     */
    private function explicitPaymentStatusExistsSubquery(array $statuses): \Closure
    {
        return function ($sub) use ($statuses) {
            $sub->selectRaw('1')
                ->from('payment_attempts as pa_explicit')
                ->whereColumn('pa_explicit.cart_id', 'carts.id')
                ->where('pa_explicit.gateway', 'efevoopay')
                ->whereIn('pa_explicit.status', $statuses)
                ->whereRaw('pa_explicit.id = '.$this->latestExplicitPaymentAttemptIdSql('carts'));
        };
    }

    private function explicitPaymentAttemptExistsSubquery(): \Closure
    {
        return function ($sub) {
            $sub->selectRaw('1')
                ->from('payment_attempts as pa_any_explicit')
                ->whereColumn('pa_any_explicit.cart_id', 'carts.id')
                ->where('pa_any_explicit.gateway', 'efevoopay');
        };
    }

    /**
     * @param  list<string>  $statuses
     */
    private function legacyPaymentStatusExistsSubquery(array $statuses): \Closure
    {
        return function ($sub) use ($statuses) {
            $sub->selectRaw('1')
                ->from('customers as payment_customers')
                ->join('payment_attempts as pa', 'pa.customer_id', '=', 'payment_customers.id')
                ->whereColumn('payment_customers.user_id', 'carts.user_id')
                ->where('pa.gateway', 'efevoopay')
                ->when($this->paymentAttemptsHaveCartId(), fn ($query) => $query->whereNull('pa.cart_id'))
                ->whereIn('pa.status', $statuses)
                ->whereRaw('ABS(pa.amount_cents - ROUND(carts.total * 100)) <= 100')
                ->whereRaw('pa.created_at >= '.$this->paymentAttemptWindowStartSql('carts'))
                ->whereRaw('pa.created_at <= '.$this->paymentAttemptWindowEndSql('carts'))
                ->whereRaw('pa.id = '.$this->latestPaymentAttemptIdSql('carts', 'payment_customers'))
                ->whereNotExists($this->ambiguousPaymentAttemptExistsSubquery('pa'));
        };
    }

    private function latestExplicitPaymentAttemptIdSql(string $cartAlias): string
    {
        $sortSql = 'COALESCE(pa_latest.processed_at, pa_latest.updated_at, pa_latest.created_at)';

        return "(SELECT pa_latest.id
            FROM payment_attempts pa_latest
            WHERE pa_latest.cart_id = {$cartAlias}.id
                AND pa_latest.gateway = 'efevoopay'
            ORDER BY {$sortSql} DESC, pa_latest.id DESC
            LIMIT 1)";
    }

    private function paymentAttemptsHaveCartId(): bool
    {
        return Schema::hasColumn('payment_attempts', 'cart_id');
    }

    private function laboratoryAppointmentsHaveCartId(): bool
    {
        return Schema::hasColumn('laboratory_appointments', 'cart_id');
    }

    /**
     * Marcas inferidas desde el snapshot en cart_items (persiste tras la compra).
     */
    private function distinctLabBrandsFromCartItems(): Collection
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->get();

        $testIds = $items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        if ($testIds->isEmpty()) {
            return collect();
        }

        if ($items->every(fn ($item) => $item->relationLoaded('laboratoryTest'))) {
            return $items
                ->map(fn ($item) => $item->laboratoryTest?->brand)
                ->filter()
                ->unique(fn (LaboratoryBrand $brand) => $brand->value)
                ->values();
        }

        return LaboratoryTest::query()
            ->whereIn('id', $testIds)
            ->pluck('brand')
            ->filter()
            ->unique(fn (LaboratoryBrand $brand) => $brand->value)
            ->values();
    }

    private function distinctLabBrandsFromCustomer(Customer $customer): Collection
    {
        $items = $customer->relationLoaded('laboratoryCartItems')
            ? $customer->laboratoryCartItems
            : $customer->laboratoryCartItems()->with('laboratoryTest')->get();

        return $items
            ->map(fn ($item) => $item->laboratoryTest?->brand)
            ->filter()
            ->unique(fn (LaboratoryBrand $brand) => $brand->value)
            ->values();
    }

    private function distinctLabBrandsFromCompletedPurchase(): Collection
    {
        $purchase = $this->relatedLaboratoryPurchase();

        return $purchase?->brand ? collect([$purchase->brand]) : collect();
    }

    private function brandsRequiringAppointment(Customer $customer): Collection
    {
        $items = $customer->relationLoaded('laboratoryCartItems')
            ? $customer->laboratoryCartItems
            : $customer->laboratoryCartItems()->with('laboratoryTest')->get();

        return $items
            ->filter(fn ($item) => $item->laboratoryTest?->requires_appointment)
            ->map(fn ($item) => $item->laboratoryTest->brand)
            ->unique(fn (LaboratoryBrand $brand) => $brand->value)
            ->values();
    }

    private function appointmentBrandsRequiringConfirmation(Customer $customer): Collection
    {
        $brands = $this->brandsRequiringAppointment($customer);
        if ($brands->isEmpty()) {
            return collect();
        }

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        return $brands->filter(function (LaboratoryBrand $brand) use ($appointments) {
            return $appointments->contains(
                fn ($appointment) => $appointment->brand === $brand && $appointment->confirmed_at === null,
            );
        });
    }

    private function requiresAppointmentForExport(): bool
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return false;
        }

        $testIds = $this->relationLoaded('items')
            ? $this->items->pluck('product_id')->filter()->unique()->values()
            : $this->items()->pluck('product_id')->filter();

        if ($testIds->isNotEmpty()) {
            return LaboratoryTest::query()
                ->whereIn('id', $testIds)
                ->where('requires_appointment', true)
                ->exists();
        }

        $customer = $this->user?->customer;
        if (! $customer) {
            return false;
        }

        return $customer->laboratoryCartItems()->requiringAppointment()->exists();
    }

    private function hasRelatedLaboratoryAppointment(): bool
    {
        $customer = $this->user?->customer;
        if (! $customer) {
            return false;
        }

        $brandValues = collect($this->labBrands())->pluck('value')->filter()->values();

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        if ($brandValues->isEmpty()) {
            return $appointments->isNotEmpty();
        }

        return $appointments->contains(
            fn (LaboratoryAppointment $appointment) => $brandValues->contains($appointment->brand->value),
        );
    }

    private function appointmentBrandsConfirmedPendingPayment(Customer $customer): Collection
    {
        $brands = $this->brandsRequiringAppointment($customer);
        if ($brands->isEmpty()) {
            return collect();
        }

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        return $brands->filter(function (LaboratoryBrand $brand) use ($appointments) {
            return $appointments->contains(
                fn ($appointment) => $appointment->brand === $brand
                    && $appointment->confirmed_at !== null
                    && $appointment->laboratory_purchase_id === null,
            );
        });
    }
}

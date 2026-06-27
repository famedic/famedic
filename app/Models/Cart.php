<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Cart extends Model
{
    public const ABANDONED_AFTER_MINUTES = 30;

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

    /**
     * Activo en carrito, abandonado (sin actividad), o comprado.
     */
    public function displayStatus(): string
    {
        if ($this->status === MonitoringCartStatus::Completed) {
            return 'completed';
        }

        if ($this->updated_at->lt(now()->subMinutes(self::ABANDONED_AFTER_MINUTES))) {
            return 'abandoned';
        }

        return 'active';
    }

    public function displayStatusLabel(): string
    {
        return match ($this->displayStatus()) {
            'completed' => 'Comprado',
            'abandoned' => 'Abandonado',
            default => 'Activo',
        };
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
            ->when($start, fn (Builder $q, Carbon $d) => $q->where('updated_at', '>=', $d))
            ->when($end, fn (Builder $q, Carbon $d) => $q->where('updated_at', '<=', $d));
    }

    public function scopeDisplayStatusFilter($query, string $status): void
    {
        if ($status === 'completed') {
            $query->where('status', MonitoringCartStatus::Completed->value);
        } elseif ($status === 'abandoned') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->where('updated_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES));
        } elseif ($status === 'active') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->where('updated_at', '>=', now()->subMinutes(self::ABANDONED_AFTER_MINUTES));
        }
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

        $customer = $this->user?->customer;
        if (! $customer) {
            return false;
        }

        return $this->appointmentBrandsRequiringConfirmation($customer)->isNotEmpty();
    }

    public function hasAppointmentConfirmedPendingPayment(): bool
    {
        if ($this->type !== MonitoringCartType::Lab || $this->status !== MonitoringCartStatus::Active) {
            return false;
        }

        $customer = $this->user?->customer;
        if (! $customer) {
            return false;
        }

        return $this->appointmentBrandsConfirmedPendingPayment($customer)->isNotEmpty();
    }

    public function relatedLaboratoryPurchase(): ?LaboratoryPurchase
    {
        if ($this->type !== MonitoringCartType::Lab) {
            return null;
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

        $brandValues = collect($this->labBrands())->pluck('value')->filter()->values();

        $appointments = $customer->relationLoaded('laboratoryAppointments')
            ? $customer->laboratoryAppointments
            : $customer->laboratoryAppointments()->get();

        return $appointments
            ->when(
                $brandValues->isNotEmpty(),
                fn (Collection $rows) => $rows->filter(
                    fn (LaboratoryAppointment $appointment) => $brandValues->contains($appointment->brand->value),
                ),
            )
            ->sortByDesc('created_at')
            ->take(10)
            ->values();
    }

    private function appointmentCartExistsSubquery(callable $appointmentConstraint): \Closure
    {
        return function ($sub) use ($appointmentConstraint) {
            $sub->selectRaw('1')
                ->from('customers as c')
                ->join('laboratory_appointments as la', 'la.customer_id', '=', 'c.id')
                ->join('laboratory_cart_items as lci', 'lci.customer_id', '=', 'c.id')
                ->join('laboratory_tests as lt', 'lt.id', '=', 'lci.laboratory_test_id')
                ->whereColumn('c.user_id', 'carts.user_id')
                ->where('lt.requires_appointment', true)
                ->whereColumn('la.brand', 'lt.brand')
                ->whereNull('lci.deleted_at')
                ->whereNull('la.deleted_at');

            $appointmentConstraint($sub);
        };
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

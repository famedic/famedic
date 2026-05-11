<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Propaganistas\LaravelPhone\Casts\RawPhoneNumberCast;

class LaboratoryAppointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_created_at',
        'formatted_confirmed_at',
        'formatted_appointment_date',
        'formatted_patient_birth_date',
        'formatted_patient_gender',
        'patient_full_name',
        'patient_full_phone',
        'appointment_date_string',
        'appointment_date_time',
        'patient_birth_date_string',
        'formatted_phone_call_intent_at',
        'formatted_callback_availability_range',
        'has_left_callback_info',
        'time_since_request_human',
        'time_since_phone_intent_human',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'datetime',
            'confirmed_at' => 'datetime',
            'phone_call_intent_at' => 'datetime',
            'callback_availability_starts_at' => 'datetime',
            'callback_availability_ends_at' => 'datetime',
            'patient_birth_date' => 'date',
            'patient_gender' => Gender::class,
            'brand' => LaboratoryBrand::class,
            'patient_phone' => RawPhoneNumberCast::class.':country_field',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function laboratoryStore(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class);
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(LaboratoryAppointmentInteraction::class);
    }

    /**
     * Compra de laboratorio vinculada con cobro en estado completado (misma lógica que "Pagado" en correos).
     */
    public function hasPaidLaboratoryPurchase(): bool
    {
        if ($this->laboratory_purchase_id === null) {
            return false;
        }

        $this->loadMissing('laboratoryPurchase.transactions');
        $purchase = $this->laboratoryPurchase;
        if ($purchase === null) {
            return false;
        }

        $transaction = $purchase->transactions->first();
        if ($transaction === null) {
            return false;
        }

        $status = strtolower((string) $transaction->payment_status);

        return in_array($status, [
            'captured',
            'completed',
            'paid',
            'success',
            'succeeded',
            'credit',
        ], true);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $completed = $filters['completed'] ?? null;
        $dateRange = $filters['date_range'] ?? null;
        $brand = $filters['brand'] ?? null;
        $phoneCallIntent = $filters['phone_call_intent'] ?? null;
        $callbackInfo = $filters['callback_info'] ?? null;

        return $query
            ->when($search, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('patient_name', 'like', "%{$search}%")
                        ->orWhere('patient_paternal_lastname', 'like', "%{$search}%")
                        ->orWhere('patient_maternal_lastname', 'like', "%{$search}%")
                        ->orWhereHas('customer.user', function (Builder $query) use ($search) {
                            $query->where(function (Builder $query) use ($search) {
                                $columns = [
                                    'name',
                                    'email',
                                    'phone',
                                    'maternal_lastname',
                                    'paternal_lastname',
                                ];

                                foreach ($columns as $column) {
                                    $query->orWhere($column, 'like', "%{$search}%");
                                }
                            });
                        });
                });
            })
            ->when(
                $completed === 'true',
                fn (Builder $query) => $query->whereNotNull('confirmed_at')
            )
            ->when(
                $completed === 'false',
                fn (Builder $query) => $query->whereNull('confirmed_at')
            )
            ->when(
                $dateRange === 'today',
                fn (Builder $query) => $query->whereDate('created_at', now()->toDateString())
            )
            ->when(
                $dateRange === 'last_7_days',
                fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)->startOfDay())
            )
            ->when(
                $dateRange === 'last_6_months',
                fn (Builder $query) => $query->where('created_at', '>=', now()->subMonths(6)->startOfDay())
            )
            ->when(
                filled($brand),
                fn (Builder $query) => $query->where('brand', $brand)
            )
            ->when(
                $phoneCallIntent === 'true',
                fn (Builder $query) => $query->whereNotNull('phone_call_intent_at')
            )
            ->when(
                $phoneCallIntent === 'false',
                fn (Builder $query) => $query->whereNull('phone_call_intent_at')
            )
            ->when(
                $callbackInfo === 'true',
                fn (Builder $query) => $query->where(function (Builder $query) {
                    $query->whereNotNull('callback_availability_starts_at')
                        ->orWhereNotNull('callback_availability_ends_at')
                        ->orWhereNotNull('patient_callback_comment');
                })
            )
            ->when(
                $callbackInfo === 'false',
                fn (Builder $query) => $query->whereNull('callback_availability_starts_at')
                    ->whereNull('callback_availability_ends_at')
                    ->where(function (Builder $query) {
                        $query->whereNull('patient_callback_comment')
                            ->orWhere('patient_callback_comment', '');
                    })
            );
    }

    public function scopeRecentlyConfirmed(Builder $query): void
    {
        $query->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', now()->subDay());
    }

    public function scopeUncompleted(Builder $query): void
    {
        $query->whereNull('laboratory_purchase_id');
    }

    public function scopeUnconfirmed(Builder $query): void
    {
        $query->whereNull('confirmed_at');
    }

    public function scopeOfBrand(Builder $query, LaboratoryBrand $brand): void
    {
        $query->where('brand', $brand->value);
    }

    protected function patientFullName(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->patient_name && $this->patient_paternal_lastname && $this->patient_maternal_lastname) {
                    return $this->patient_name.' '.$this->patient_paternal_lastname.' '.$this->patient_maternal_lastname;
                }

                return null;
            }
        );
    }

    protected function patientFullPhone(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->patient_phone?->formatE164()
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->created_at->diffForHumans(),
        );
    }

    protected function formattedConfirmedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->confirmed_at?->isoFormat('LL'),
        );
    }

    protected function formattedAppointmentDate(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->appointment_date)?->isoFormat('ddd D MMM h:mm a'),
        );
    }

    protected function formattedPatientBirthDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->patient_birth_date?->isoFormat('LL'),
        );
    }

    protected function formattedPatientGender(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->patient_gender?->label()
        );
    }

    protected function patientBirthDateString(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->patient_birth_date?->format('Y-m-d'),
        );
    }

    protected function appointmentDateString(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->appointment_date)?->format('Y-m-d'),
        );
    }

    protected function appointmentDateTime(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->appointment_date)?->format('H:i'),
        );
    }

    protected function formattedPhoneCallIntentAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->phone_call_intent_at)?->isoFormat('ddd D MMM YYYY, h:mm a'),
        );
    }

    protected function formattedCallbackAvailabilityRange(): Attribute
    {
        return Attribute::make(
            get: function () {
                $start = localizedDate($this->callback_availability_starts_at);
                $end = localizedDate($this->callback_availability_ends_at);
                if (! $start && ! $end) {
                    return null;
                }
                if ($start && $end) {
                    return $start->isoFormat('ddd D MMM YYYY, h:mm a')
                        .' — '
                        .$end->isoFormat('ddd D MMM YYYY, h:mm a');
                }

                return $start?->isoFormat('ddd D MMM YYYY, h:mm a')
                    ?? $end?->isoFormat('ddd D MMM YYYY, h:mm a');
            }
        );
    }

    protected function hasLeftCallbackInfo(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->callback_availability_starts_at !== null
                || $this->callback_availability_ends_at !== null
                || filled($this->patient_callback_comment),
        );
    }

    protected function timeSinceRequestHuman(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->created_at->locale('es')->diffForHumans(),
        );
    }

    protected function timeSincePhoneIntentHuman(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->phone_call_intent_at?->locale('es')->diffForHumans(),
        );
    }
}

<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'datetime',
            'confirmed_at' => 'datetime',
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

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $completed = $filters['completed'] ?? null;

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
}

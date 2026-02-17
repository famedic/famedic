<?php

namespace App\Models;

use App\Enums\Gender;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Propaganistas\LaravelPhone\Casts\RawPhoneNumberCast;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'birth_date_string',
        'formatted_birth_date',
        'formatted_gender',
        'full_name',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Gender::class,
            'phone' => RawPhoneNumberCast::class . ':country_field',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function birthDateString(): Attribute
    {
        return Attribute::make(
            get: fn() => Carbon::parse($this->birth_date)->format('Y-m-d'),
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->name . ' ' . $this->paternal_lastname . ' ' . $this->maternal_lastname
        );
    }

    protected function formattedBirthDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->birth_date->isoFormat('D [de] MMM [de] YYYY'),
        );
    }

    protected function formattedGender(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->gender->label()
        );
    }

    public function scopeOfCustomer(Builder $query, Customer $customer)
    {
        return $query->whereCustomerId($customer->id);
    }

    /**
     * Formatear contacto para el checkout
     */
    public function forCheckout(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'paternal_lastname' => $this->paternal_lastname,
            'maternal_lastname' => $this->maternal_lastname,
            'full_name' => $this->getFullNameAttribute(),
            'birth_date' => $this->birth_date,
            'formatted_birth_date' => $this->birth_date 
                ? \Carbon\Carbon::parse($this->birth_date)->format('d/m/Y')
                : null,
            'gender' => $this->gender,
            'formatted_gender' => $this->getFormattedGenderAttribute(),
            'phone' => $this->phone,
            'phone_country' => $this->phone_country,
            'formatted_phone' => $this->getFormattedPhoneAttribute(),
        ];
    }

    /**
     * Atributo: Nombre completo
     */
    public function getFullNameAttribute(): string
    {
        $parts = [
            $this->name,
            $this->paternal_lastname,
            $this->maternal_lastname,
        ];

        return implode(' ', array_filter($parts, function ($part) {
            return !empty($part) && trim($part) !== '';
        }));
    }

    /**
     * Atributo: Género formateado
     */
    public function getFormattedGenderAttribute(): string
    {
        return match($this->gender) {
            'M' => 'Masculino',
            'F' => 'Femenino',
            default => 'No especificado',
        };
    }

    /**
     * Atributo: Teléfono formateado
     */
    public function getFormattedPhoneAttribute(): string
    {
        if (empty($this->phone)) {
            return '';
        }

        // Formato básico: +52 55 1234 5678
        $phone = preg_replace('/\D/', '', $this->phone);
        
        if (strlen($phone) === 10) {
            return '+52 ' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 4) . ' ' . substr($phone, 6, 4);
        }
        
        return $this->phone;
    }
}

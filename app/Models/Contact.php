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
}

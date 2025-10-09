<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\Kinship;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class FamilyAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'profile_photo_url',
        'formatted_kinship',
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
            'kinship' => Kinship::class,
        ];
    }

    public function customer()
    {
        return $this->morphOne(Customer::class, 'customerable');
    }

    public function parentCustomer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    protected function birthDateString(): Attribute
    {
        return Attribute::make(
            get: fn() => Carbon::parse($this->birth_date)?->format('Y-m-d'),
        );
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $name = trim(collect(explode(' ', $this->name))->map(function ($segment) {
                    return mb_substr($segment, 0, 1);
                })->join(' '));

                return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&color=7F9CF5&background=EBF4FF';
            },
        );
    }

    protected function formattedBirthDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->birth_date?->isoFormat('D [de] MMM [de] YYYY'),
        );
    }

    protected function formattedGender(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->gender?->label()
        );
    }

    protected function formattedKinship(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->kinship->label()
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->name . ' ' . $this->paternal_lastname . ' ' . $this->maternal_lastname
        );
    }
}

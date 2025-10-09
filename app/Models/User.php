<?php

namespace App\Models;

use App\Enums\Gender;
use App\Interfaces\MustVerifyPhone;
use App\Traits\MustVerifyPhone as TraitsMustVerifyPhone;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Propaganistas\LaravelPhone\Casts\RawPhoneNumberCast;

class User extends Authenticatable implements MustVerifyEmail, MustVerifyPhone
{
    use HasFactory, Notifiable, TraitsMustVerifyPhone;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'profile_photo_url',
        'birth_date_string',
        'full_name',
        'full_phone',
        'formatted_birth_date',
        'formatted_gender',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'documentation_accepted_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'gender' => Gender::class,
            'phone' => RawPhoneNumberCast::class.':country_field',
        ];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function administrator(): HasOne
    {
        return $this->hasOne(Administrator::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Check if full_name has actual content (not just spaces)
                $fullName = trim($this->full_name);
                $displayName = $fullName ?: $this->email;

                if (! $displayName) {
                    return 'https://ui-avatars.com/api/?name=U&color=7F9CF5&background=EBF4FF';
                }

                // For email, use the part before @ symbol
                if (str_contains($displayName, '@')) {
                    $displayName = explode('@', $displayName)[0];
                }

                $name = trim(collect(explode(' ', $displayName))->map(function ($segment) {
                    return mb_substr($segment, 0, 1);
                })->join(' '));

                return 'https://ui-avatars.com/api/?name='.urlencode($name).'&color=7F9CF5&background=EBF4FF';
            },
        );
    }

    protected function birthDateString(): Attribute
    {
        return Attribute::make(
            get: fn () => Carbon::parse($this->birth_date)->format('Y-m-d'),
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: function () {
                $parts = array_filter([
                    $this->name,
                    $this->paternal_lastname,
                    $this->maternal_lastname,
                ]);

                return implode(' ', $parts);
            }
        );
    }

    protected function fullPhone(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->phone?->formatE164()
        );
    }

    protected function formattedBirthDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->birth_date?->isoFormat('D [de] MMM [de] YYYY'),
        );
    }

    protected function formattedGender(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->gender?->label()
        );
    }

    protected function profileIsComplete(): Attribute
    {
        return Attribute::make(
            get: fn () => ! empty($this->name) &&
                ! empty($this->paternal_lastname) &&
                ! empty($this->maternal_lastname) &&
                ! empty($this->phone) &&
                ! empty($this->birth_date) &&
                ! empty($this->gender)
        );
    }

    public function routeNotificationForVonage(Notification $notification): string
    {
        return $this->phone?->formatInternational();
    }
}

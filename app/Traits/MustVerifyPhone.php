<?php

namespace App\Traits;

use App\Notifications\SendPhoneVerificationCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Tzsk\Otp\Facades\Otp;

trait MustVerifyPhone
{
    public function hasVerifiedPhone(): Attribute
    {
        return Attribute::make(
            get: fn() => ! is_null($this->phone_verified_at)
        );
    }

    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function sendPhoneVerificationNotification(): void
    {
        $phoneVerificationCode = Otp::generate(md5($this->email));

        $this->notify(new SendPhoneVerificationCode($phoneVerificationCode));
    }
}

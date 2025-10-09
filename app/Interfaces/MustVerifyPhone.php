<?php

namespace App\Interfaces;

interface MustVerifyPhone
{
    public function hasVerifiedPhone();

    public function markPhoneAsVerified();

    public function sendPhoneVerificationNotification();
}

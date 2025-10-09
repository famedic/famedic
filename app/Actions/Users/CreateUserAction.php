<?php

namespace App\Actions\Users;

use App\Enums\Gender;
use App\Models\User;
use App\Notifications\ReferralSignupNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Propaganistas\LaravelPhone\PhoneNumber;

class CreateUserAction
{
    public function __invoke(
        string $email,
        ?string $name = null,
        ?string $paternalLastname = null,
        ?string $maternalLastname = null,
        ?Carbon $birthDate = null,
        ?Gender $gender = null,
        ?string $phone = null,
        ?string $phoneCountry = null,
        ?string $password = null,
        bool $documentationAccepted = false,
        ?int $referrerUserId = null,
    ): User {
        $user = User::create([
            'name' => $name,
            'paternal_lastname' => $paternalLastname,
            'maternal_lastname' => $maternalLastname,
            'birth_date' => $birthDate?->toDateString(),
            'gender' => $gender?->value,
            'phone' => $phone && $phoneCountry ? str_replace(' ', '', (new PhoneNumber($phone, $phoneCountry))->formatNational()) : null,
            'phone_country' => $phoneCountry,
            'email' => $email,
            'password' => $password ? Hash::make($password) : null,
            'documentation_accepted_at' => $documentationAccepted ? now() : null,
            'referred_by' => $referrerUserId,
        ]);

        // Send notification to referrer if user was referred
        if ($referrerUserId) {
            $referrer = User::find($referrerUserId);
            if ($referrer) {
                $referrer->notify(new ReferralSignupNotification($user));
            }
        }

        return $user;
    }
}

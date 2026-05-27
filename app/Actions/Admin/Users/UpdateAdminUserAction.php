<?php

namespace App\Actions\Admin\Users;

use App\Models\User;
use Propaganistas\LaravelPhone\PhoneNumber;

class UpdateAdminUserAction
{
    public function __invoke(User $user, array $data): User
    {
        $phone = str_replace(' ', '', (new PhoneNumber($data['phone'], $data['phone_country']))->formatNational());
        $email = strtolower($data['email']);

        $user->fill([
            'name' => $data['name'],
            'paternal_lastname' => $data['paternal_lastname'],
            'maternal_lastname' => $data['maternal_lastname'],
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'],
            'state' => $data['state'] ?? null,
            'email' => $email,
            'phone' => $phone,
            'phone_country' => $data['phone_country'],
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($user->isDirty('phone') || $user->isDirty('phone_country')) {
            $user->phone_verified_at = null;
        }

        $user->save();

        return $user->fresh();
    }
}

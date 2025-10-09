<?php

namespace App\Actions\Contacts;

use App\Enums\Gender;
use App\Models\Contact;
use App\Models\Customer;
use Carbon\Carbon;
use Propaganistas\LaravelPhone\PhoneNumber;

class CreateContactAction
{
    public function __invoke(
        string $name,
        string $paternal_lastname,
        string $maternal_lastname,
        Carbon $birth_date,
        Gender $gender,
        string $phone,
        string $phone_country,
        Customer $customer
    ): Contact {
        return $customer->contacts()->create([
            'name' => $name,
            'paternal_lastname' => $paternal_lastname,
            'maternal_lastname' => $maternal_lastname,
            'birth_date' => $birth_date->toDateString(),
            'gender' => $gender->value,
            'phone' => str_replace(' ', '', (new PhoneNumber($phone, $phone_country))->formatNational()),
            'phone_country' => $phone_country,
        ]);
    }
}

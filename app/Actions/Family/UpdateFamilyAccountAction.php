<?php

namespace App\Actions\Family;

use App\Enums\Gender;
use App\Enums\Kinship;
use App\Models\FamilyAccount;
use Carbon\Carbon;

class UpdateFamilyAccountAction
{
    public function __invoke(
        string $name,
        string $paternal_lastname,
        string $maternal_lastname,
        Carbon $birth_date,
        Gender $gender,
        Kinship $kinship,
        FamilyAccount $familyAccount
    ): FamilyAccount {
        $familyAccount->update([
            'name' => $name,
            'paternal_lastname' => $paternal_lastname,
            'maternal_lastname' => $maternal_lastname,
            'birth_date' => $birth_date->toDateString(),
            'gender' => $gender->value,
            'kinship' => $kinship->value,
        ]);

        return $familyAccount;
    }
}

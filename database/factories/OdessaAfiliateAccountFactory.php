<?php

namespace Database\Factories;

use App\Models\OdessaAfiliatedCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

class OdessaAfiliateAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'odessa_identifier' => fake()->unique()->randomNumber(),
            'partner_identifier' => fake()->unique()->randomNumber(),
            'odessa_afiliated_company_id' => OdessaAfiliatedCompany::factory()
        ];
    }
}

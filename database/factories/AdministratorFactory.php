<?php

namespace Database\Factories;

use App\Models\Administrator;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdministratorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }

    public function withRole($roleName)
    {
        return $this->afterCreating(function (Administrator $administrator) use ($roleName) {
            $administrator->assignRole(Role::whereName($roleName)->sole());
        });
    }
}

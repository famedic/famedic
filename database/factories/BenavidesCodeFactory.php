<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BenavidesCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('############'),
            'user_id' => null,
            'assigned_at' => null,
            'import_batch_id' => null,
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }
}

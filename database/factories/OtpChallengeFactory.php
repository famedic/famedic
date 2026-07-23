<?php

namespace Database\Factories;

use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<OtpChallenge>
 */
class OtpChallengeFactory extends Factory
{
    protected $model = OtpChallenge::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => null,
            'subject_type' => 'email',
            'subject_key' => fake()->unique()->safeEmail(),
            'purpose' => P0aOtpPurpose::AkubicaLogin->value,
            'channel' => P0aOtpChannel::Email->value,
            'destination_normalized' => null,
            'destination_masked' => 'u***@example.com',
            'code_hash' => Hash::make('001234'),
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'invalidated_at' => null,
            'invalidated_reason' => null,
            'failed_attempts' => 0,
            'max_attempts' => 5,
            'send_count' => 0,
            'last_sent_at' => null,
            'context_type' => null,
            'context_id' => null,
            'meta' => null,
        ];
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(function () use ($user) {
            $user ??= User::factory()->create();

            return [
                'user_id' => $user->id,
                'subject_type' => 'user',
                'subject_key' => (string) $user->id,
            ];
        });
    }

    public function consumed(): static
    {
        return $this->state(fn () => [
            'consumed_at' => now(),
        ]);
    }

    public function invalidated(string $reason = 'superseded'): static
    {
        return $this->state(fn () => [
            'invalidated_at' => now(),
            'invalidated_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}

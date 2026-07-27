<?php

namespace Database\Factories;

use App\Enums\AkubicaRegistrationIntentStatus;
use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Services\Otp\Registration\AkubicaRegistrationPayload;
use App\Services\Otp\Registration\AkubicaRegistrationPayloadCipher;
use App\Services\Otp\Registration\EmailNormalizer;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\RegistrationIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AkubicaRegistrationIntent>
 */
class AkubicaRegistrationIntentFactory extends Factory
{
    protected $model = AkubicaRegistrationIntent::class;

    public function definition(): array
    {
        return [
            'otp_challenge_id' => OtpChallenge::factory()->state([
                'purpose' => 'akubica_register',
                'expires_at' => now()->addMinutes(10),
            ]),
            'status' => AkubicaRegistrationIntentStatus::Pending,
            'encrypted_payload' => null,
            'payload_version' => AkubicaRegistrationPayload::VERSION,
            'email_fingerprint' => hash('sha256', 'test-fp-'.fake()->unique()->numerify('####')),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
            'invalidated_at' => null,
            'invalidation_reason' => null,
            'superseded_by_id' => null,
        ];
    }

    public function withEncryptedPayload(
        string $email = 'reg.intent@ejemplo.test',
        string $phone = '5512345600',
        string $fullName = 'Nombre Prueba',
    ): static {
        return $this->state(function () use ($email, $phone, $fullName) {
            $identity = new RegistrationIdentity(
                email: app(EmailNormalizer::class)->normalize($email),
                phone: app(MexicoPhoneNormalizer::class)->normalize($phone, 'MX'),
                fullName: $fullName,
            );
            $payload = AkubicaRegistrationPayload::fromIdentity($identity);

            return [
                'encrypted_payload' => app(AkubicaRegistrationPayloadCipher::class)->encrypt($payload),
                'payload_version' => $payload->payloadVersion,
                'email_fingerprint' => hash('sha256', 'fp|'.$email),
            ];
        });
    }

    public function consumed(): static
    {
        return $this->state(fn () => [
            'status' => AkubicaRegistrationIntentStatus::Consumed,
            'consumed_at' => now(),
            'encrypted_payload' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => AkubicaRegistrationIntentStatus::Expired,
            'expires_at' => now()->subMinute(),
            'encrypted_payload' => null,
        ]);
    }
}

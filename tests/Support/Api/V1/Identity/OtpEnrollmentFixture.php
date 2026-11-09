<?php

namespace Tests\Support\Api\V1\Identity;

use App\Models\ApiConsumerDelegation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use App\Services\Api\V1\Auth\OtpConsumerEnrollment;
use App\Services\Api\V1\Auth\OtpEnrollmentOperator;
use App\Services\Api\V1\Auth\RegisteredOtpConsumerEnrollment;

final class OtpEnrollmentFixture
{
    private static ?array $actor = null;

    public static function existing(User $user, string $kind = 'leo'): void
    {
        self::$actor = SyntheticConsumerRegistryFixture::issue($user, $kind, ['memberships:read', 'cart:read']);
        self::enable();
    }

    public static function registration(string $email): void
    {
        self::$actor = SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), 'native');
        Customer::created(function (Customer $customer) use ($email) {
            $user = $customer->user;
            if ($user?->email !== $email) {
                return;
            }
            self::$actor['user'] = $user;
            self::$actor['delegation'] = ApiConsumerDelegation::forceCreate(['consumer_id' => self::$actor['consumer']->id, 'channel_id' => self::$actor['channel']->id, 'user_id' => $user->id, 'customer_id' => $customer->id, 'capabilities' => self::$actor['consumer']->capabilities, 'enabled' => true, 'expires_at' => now()->addHour()]);
        });
        self::enable();
    }

    private static function enable(): void
    {
        app()->bind(OtpConsumerEnrollment::class, RegisteredOtpConsumerEnrollment::class);
        app()->instance(OtpEnrollmentOperator::class, new class implements OtpEnrollmentOperator
        {
            public function operatorFor(AuthenticatedBackendClient $client, User $verifiedUser): ?User
            {
                return OtpEnrollmentFixture::operator($client, $verifiedUser);
            }
        });
    }

    public static function operator(AuthenticatedBackendClient $client, User $user): ?User
    {
        $a = self::$actor;

        return $a && $client->consumerId === (string) $a['consumer']->id && $client->channelId === (string) $a['channel']->id && $user->id === $a['user']->id ? $a['issuer'] : null;
    }

    public static function headers(): array
    {
        return ['X-Synthetic-Machine-Credential' => self::$actor['machine']->plainTextToken];
    }

    public static function issued(string $raw): void
    {
        SyntheticConsumerRegistryFixture::associateIssuedToken($raw, self::$actor);
    }
}

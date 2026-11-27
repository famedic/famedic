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

    public static function reset(): void
    {
        self::$actor = null;
    }

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

    /**
     * INT-031 (harness only): one registered OTP backend actor for a legacy test that predates the enrollment
     * contract. The enrollment operator answers for any verified user WITH a customer by ensuring that user has
     * the single active delegation to this consumer/channel (the precondition "this customer delegated to the
     * consumer"). RegisteredOtpConsumerEnrollment still runs every server-side check unchanged: authenticated
     * machine credential, customer present, active consumer/channel/delegation, exactly one delegation, literal
     * non-'*' capabilities and expiry. A request without this machine credential is still rejected.
     */
    public static function anyCustomer(string $kind = 'native', ?array $caps = null): void
    {
        self::$actor = SyntheticConsumerRegistryFixture::issue(User::factory()->withRegularCustomer()->create(), $kind, $caps);
        self::$actor['any_customer'] = true;
        // Registry rows live for the whole legacy test (two days) so the application's own TTL rules (e.g. P0-C1
        // 180/1440 minutes) are what bound the issued token, not the short default fixture lifetime.
        $until = now()->addDays(2);
        foreach (['consumer', 'channel', 'credential'] as $key) {
            self::$actor[$key]->forceFill(['expires_at' => $until])->save();
        }
        self::$actor['machine']->accessToken->forceFill(['expires_at' => $until])->save();
        self::$actor['baseline'] = ['users' => User::query()->count(), 'customers' => Customer::query()->count(), 'regular_accounts' => \App\Models\RegularAccount::query()->count()];
        self::enable();
    }

    /** INT-031: row counts right after the harness actor was enrolled (users, customers, regular_accounts). */
    public static function baseline(string $table): int
    {
        return (int) (self::$actor['baseline'][$table] ?? 0);
    }

    /** INT-031: ids of the PATs that belong to the harness actor itself (its user token and its machine credential). */
    public static function harnessTokenIds(): array
    {
        return isset(self::$actor['machine']) ? [(int) self::$actor['machine']->accessToken->id, (int) self::$actor['token']->accessToken->id] : [];
    }

    /** INT-031: raw machine credential of the current actor, or null when no actor was enrolled. */
    public static function machine(): ?string
    {
        return isset(self::$actor['machine']) ? self::$actor['machine']->plainTextToken : null;
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
        if ($a && ($a['any_customer'] ?? false) && $client->consumerId === (string) $a['consumer']->id && $client->channelId === (string) $a['channel']->id && $user->customer) {
            $attributes = ['consumer_id' => $a['consumer']->id, 'channel_id' => $a['channel']->id, 'user_id' => $user->id, 'customer_id' => $user->customer->id];
            if (! ApiConsumerDelegation::query()->where($attributes)->exists()) {
                ApiConsumerDelegation::forceCreate($attributes + ['capabilities' => $a['consumer']->capabilities, 'enabled' => true, 'expires_at' => $a['consumer']->expires_at]);
            }

            return $a['issuer'];
        }

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

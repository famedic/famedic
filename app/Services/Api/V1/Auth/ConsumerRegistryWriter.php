<?php

namespace App\Services\Api\V1\Auth;

use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Trusted internal provisioning only. No external registration endpoint. All
 * records start disabled; no default enrollment/activation permission exists.
 */
final class ConsumerRegistryWriter
{
    public function register(User $operator, string $kind, array $capabilities, Carbon $expiresAt): ApiConsumer
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $caps = ConsumerRegistryState::capabilities($capabilities);
        if (! in_array($kind, ['leo', 'native'], true) || ! $expiresAt->isFuture()) {
            throw new \DomainException('Invalid consumer enrollment');
        }
        foreach ($caps as $cap) {
            if ($kind === 'leo' && ($cap === PaymentLinkAccessPolicy::CAPABILITY || str_starts_with($cap, 'payments:') || str_starts_with($cap, 'legacy-benefits:'))) {
                throw new \DomainException('Forbidden consumer grant');
            }
        }

        return ApiConsumer::forceCreate(['kind' => $kind, 'capabilities' => $caps, 'enabled' => false, 'expires_at' => $expiresAt]);
    }

    public function channel(User $operator, ApiConsumer $consumer, Carbon $expiresAt): ApiConsumerChannel
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $current = ApiConsumer::query()->findOrFail($consumer->id);
        if (! $expiresAt->isFuture() || $expiresAt->gt($current->expires_at)) {
            throw new \DomainException('Invalid channel lifetime');
        }

        return ApiConsumerChannel::forceCreate(['consumer_id' => $current->id, 'enabled' => false, 'expires_at' => $expiresAt]);
    }

    public function delegate(User $operator, ApiConsumerChannel $channel, User $user, array $capabilities, Carbon $expiresAt): ApiConsumerDelegation
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');

        return DB::transaction(function () use ($channel, $user, $capabilities, $expiresAt) {
            $current = ApiConsumerChannel::query()->lockForUpdate()->findOrFail($channel->id);
            $consumer = ApiConsumer::query()->findOrFail($current->consumer_id);
            $customers = Customer::query()->where('user_id', $user->id)->limit(2)->get();
            $caps = ConsumerRegistryState::capabilities($capabilities);
            if ($customers->count() !== 1 || ! $expiresAt->isFuture() || $expiresAt->gt($current->expires_at)
                || $expiresAt->gt($consumer->expires_at) || array_diff($caps, ConsumerRegistryState::capabilities($consumer->capabilities))) {
                throw new \DomainException('Invalid delegation context');
            }

            return ApiConsumerDelegation::forceCreate(['consumer_id' => $consumer->id, 'channel_id' => $current->id, 'user_id' => $user->id, 'customer_id' => $customers->sole()->id, 'capabilities' => $caps, 'enabled' => false, 'expires_at' => $expiresAt]);
        });
    }
}

<?php

namespace App\Services\Api\V1\Auth;

use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/** Internal enrollment only. No default Gate grant, HTTP endpoint or CLI issuer. */
final class ConsumerTokenBindingWriter
{
    public function bind(User $operator, PersonalAccessToken $token, ApiConsumerDelegation $delegation, array $capabilities, Carbon $expiresAt): ApiConsumerTokenBinding
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');

        return DB::transaction(function () use ($token, $delegation, $capabilities, $expiresAt) {
            $pat = $token->newQuery()->lockForUpdate()->findOrFail($token->getKey());
            $grant = ApiConsumerDelegation::query()->lockForUpdate()->findOrFail($delegation->getKey());
            $consumer = ApiConsumer::query()->lockForUpdate()->findOrFail($grant->consumer_id);
            $channel = ApiConsumerChannel::query()->lockForUpdate()->findOrFail($grant->channel_id);
            $owner = $pat->tokenable;
            if (! $pat->created_at || ($pat->expires_at && ! $pat->expires_at->isFuture())
                || ($pat->expires_at && $expiresAt->gt($pat->expires_at))
                || (config('sanctum.expiration') && ! $pat->created_at->gt(now()->subMinutes(config('sanctum.expiration'))))) {
                throw new \DomainException('Invalid delegated credential lifetime');
            }
            if (! $owner instanceof User || (int) $grant->user_id !== (int) $owner->id
                || Customer::query()->where('id', $grant->customer_id)->where('user_id', $owner->id)->count() !== 1
                || (int) $channel->consumer_id !== (int) $consumer->id
                || ! ConsumerRegistryState::active($consumer) || ! ConsumerRegistryState::active($channel)
                || ! ConsumerRegistryState::active($grant) || ! $expiresAt->isFuture()
                || $expiresAt->gt($consumer->expires_at) || $expiresAt->gt($channel->expires_at) || $expiresAt->gt($grant->expires_at)) {
                throw new \DomainException('Invalid binding context');
            }
            $caps = ConsumerRegistryState::capabilities($capabilities);
            if (array_diff($caps, ConsumerRegistryState::capabilities($consumer->capabilities))
                || array_diff($caps, ConsumerRegistryState::capabilities($grant->capabilities))
                || ! is_array($pat->abilities) || array_diff($caps, $pat->abilities)) {
                throw new \DomainException('Invalid binding grants');
            }
            if (ApiConsumerTokenBinding::query()->where('personal_access_token_id', $pat->getKey())->exists()) {
                throw new \DomainException('Binding already exists');
            }

            return ApiConsumerTokenBinding::forceCreate(['personal_access_token_id' => $pat->getKey(), 'consumer_id' => $consumer->id, 'channel_id' => $channel->id,
                'delegation_id' => $grant->id, 'capabilities' => $caps, 'enabled' => true, 'expires_at' => $expiresAt]);
        });
    }
}

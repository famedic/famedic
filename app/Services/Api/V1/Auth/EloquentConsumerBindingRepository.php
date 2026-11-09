<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\ConsumerBindingRepository;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Customer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

final class EloquentConsumerBindingRepository implements ConsumerBindingRepository
{
    public function findByTokenId(int $tokenId): ?ConsumerAuthorizationContext
    {
        $rows = ApiConsumerTokenBinding::query()->where('personal_access_token_id', $tokenId)->limit(2)->get();
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->count() !== 1) {
            throw new \UnexpectedValueException('Ambiguous binding');
        }
        $binding = $rows->sole();
        $model = Sanctum::$personalAccessTokenModel;
        $pat = $model::find($tokenId);
        $user = $pat?->tokenable;
        if (! $user instanceof User) {
            throw new \UnexpectedValueException('Invalid delegated principal');
        }
        $customers = Customer::query()->where('user_id', $user->getKey())->limit(2)->get();
        if ($customers->count() !== 1) {
            throw new \UnexpectedValueException('Ambiguous customer');
        }
        $consumer = ApiConsumer::query()->findOrFail($binding->consumer_id);
        $channel = ApiConsumerChannel::query()->findOrFail($binding->channel_id);
        $delegation = ApiConsumerDelegation::query()->findOrFail($binding->delegation_id);
        if ((int) $channel->consumer_id !== (int) $consumer->id || (int) $delegation->consumer_id !== (int) $consumer->id
            || (int) $delegation->channel_id !== (int) $channel->id || (int) $delegation->user_id !== (int) $user->id
            || (int) $delegation->customer_id !== (int) $customers->sole()->id) {
            throw new \UnexpectedValueException('Incoherent binding');
        }
        $grants = array_values(array_intersect(ConsumerRegistryState::capabilities($consumer->capabilities), ConsumerRegistryState::capabilities($binding->capabilities)));
        $expires = min($consumer->expires_at->timestamp, $channel->expires_at->timestamp, $delegation->expires_at->timestamp, $binding->expires_at->timestamp);

        return new ConsumerAuthorizationContext((string) $consumer->id, $consumer->kind, (string) $channel->id, (string) $delegation->id,
            (int) $user->id, $tokenId, $grants, ConsumerRegistryState::capabilities($delegation->capabilities), $expires,
            ConsumerRegistryState::active($consumer), ConsumerRegistryState::active($channel), ConsumerRegistryState::active($delegation), ConsumerRegistryState::active($binding));
    }
}

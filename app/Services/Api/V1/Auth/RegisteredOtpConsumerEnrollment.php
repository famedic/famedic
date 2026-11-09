<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/** Dormant until explicit approval/DI binding. Uses only verified machine principal and server registry. */
final class RegisteredOtpConsumerEnrollment implements OtpConsumerEnrollment
{
    public function __construct(private BackendClientAuthenticator $backend, private OtpEnrollmentOperator $authority) {}

    public function grant(User $verifiedUser, Request $request): ?OtpEnrollmentGrant
    {
        $client = $this->backend->authenticate($request);
        if (! $client || ! $verifiedUser->customer) {
            throw new AuthorizationException;
        }
        $operator = $this->authority->operatorFor($client, $verifiedUser);
        if (! $operator) {
            throw new AuthorizationException;
        }
        $consumer = ApiConsumer::query()->lockForUpdate()->find($client->consumerId);
        $channel = ApiConsumerChannel::query()->lockForUpdate()->find($client->channelId);
        $delegations = ApiConsumerDelegation::query()->where('consumer_id', $client->consumerId)->where('channel_id', $client->channelId)->where('user_id', $verifiedUser->id)->where('customer_id', $verifiedUser->customer->id)->lockForUpdate()->get();
        $active = $delegations->filter(fn ($d) => ConsumerRegistryState::active($d));
        if (! $consumer || ! in_array($consumer->kind, ['native', 'leo'], true) || ! $channel || (int) $channel->consumer_id !== (int) $consumer->id || ! ConsumerRegistryState::active($consumer) || ! ConsumerRegistryState::active($channel) || $active->count() !== 1 || $client->expiresAt <= now()->timestamp) {
            throw new AuthorizationException;
        }
        $delegation = $active->sole();
        $caps = array_values(array_intersect(ConsumerRegistryState::capabilities($consumer->capabilities), ConsumerRegistryState::capabilities($delegation->capabilities)));
        if (! $caps || in_array('*', $caps, true)) {
            throw new AuthorizationException;
        }

        return new OtpEnrollmentGrant($operator, $delegation, $caps, min($client->expiresAt, $consumer->expires_at->timestamp, $channel->expires_at->timestamp, $delegation->expires_at->timestamp));
    }
}

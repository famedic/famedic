<?php

namespace App\Services\Api\V1\Auth;

use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerCredential;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** No external enrollment endpoint. Return the freshly issued plaintext only
 * to the trusted caller in memory; never store or log it. Gate has no default grant.
 */
final class ConsumerMachineCredentialIssuer
{
    public function issue(User $operator, ApiConsumerChannel $channel, Carbon $expiresAt): array
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');

        return DB::transaction(function () use ($channel, $expiresAt) {
            $current = ApiConsumerChannel::query()->lockForUpdate()->findOrFail($channel->id);
            $consumer = ApiConsumer::query()->lockForUpdate()->findOrFail($current->consumer_id);
            if (! ConsumerRegistryState::active($current) || ! ConsumerRegistryState::active($consumer)
                || ! $expiresAt->isFuture() || $expiresAt->gt($current->expires_at) || $expiresAt->gt($consumer->expires_at)) {
                throw new \DomainException('Invalid credential issuance context');
            }
            $token = $consumer->createToken('machine', [RegisteredMachinePatBackendAuthenticator::CAPABILITY], $expiresAt);
            $credential = ApiConsumerCredential::forceCreate(['consumer_id' => $consumer->id, 'channel_id' => $current->id, 'personal_access_token_id' => $token->accessToken->id, 'enabled' => true, 'expires_at' => $expiresAt]);

            return compact('token', 'credential');
        });
    }
}

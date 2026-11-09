<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerCredential;
use Illuminate\Http\Request;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\Sanctum;

/** Real Sanctum machine-token verification, independent of delegated user PAT.
 * No external header/protocol is installed by this adapter.
 */
final class RegisteredMachinePatBackendAuthenticator implements BackendClientAuthenticator
{
    public const CAPABILITY = 'client:authenticate';

    public function __construct(private BackendCredentialSource $source) {}

    public function authenticate(Request $request): ?AuthenticatedBackendClient
    {
        $raw = $this->source->credentialFor($request);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $model = Sanctum::$personalAccessTokenModel;
        $token = $model::findToken($raw);
        if (! $token || ! $token->tokenable instanceof ApiConsumer) {
            return null;
        }
        $consumer = $token->tokenable;
        $abilities = $token->abilities;
        if (! is_array($abilities) || in_array('*', $abilities, true) || ! in_array(self::CAPABILITY, $abilities, true)
            || ! $token->created_at || ($token->expires_at && ! $token->expires_at->isFuture())) {
            return null;
        }
        $expiration = config('sanctum.expiration');
        if ($expiration && ! $token->created_at->gt(now()->subMinutes($expiration))) {
            return null;
        }
        // Reuse the installed Sanctum validation callback as an additional veto.
        $rules = new class(app('auth'), $expiration, null) extends Guard
        {
            public function accepts($token): bool
            {
                return $this->isValidAccessToken($token);
            }
        };
        if (! $rules->accepts($token)) {
            return null;
        }
        $credentials = ApiConsumerCredential::query()->where('personal_access_token_id', $token->getKey())->limit(2)->get();
        if ($credentials->count() !== 1) {
            return null;
        }$credential = $credentials->sole();
        $channel = ApiConsumerChannel::query()->find($credential->channel_id);
        if (! $channel || (int) $credential->consumer_id !== (int) $consumer->id || (int) $channel->consumer_id !== (int) $consumer->id
            || ! ConsumerRegistryState::active($consumer) || ! ConsumerRegistryState::active($channel) || ! ConsumerRegistryState::active($credential)) {
            return null;
        }
        $expires = min($consumer->expires_at->timestamp, $channel->expires_at->timestamp, $credential->expires_at->timestamp,
            $token->expires_at?->timestamp ?? PHP_INT_MAX, $expiration ? $token->created_at->addMinutes($expiration)->timestamp : PHP_INT_MAX);

        return new AuthenticatedBackendClient((string) $consumer->id, (string) $channel->id, $expires);
    }
}

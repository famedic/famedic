<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Models\ApiConsumerDelegation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class NativeConsumerMigration
{
    public function renew(User $operator, ApiConsumerDelegation $delegation, Request $request, Carbon $expires): array
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $machine = app(BackendClientAuthenticator::class)->authenticate($request);
        if (! $machine || (string) $delegation->consumer_id !== $machine->consumerId || (string) $delegation->channel_id !== $machine->channelId || ! $expires->isFuture() || $expires->timestamp > $machine->expiresAt) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        $expires = Carbon::createFromTimestampUTC($expires->timestamp);

        return DB::transaction(function () use ($operator, $delegation, $expires) {
            $d = $delegation->newQuery()->lockForUpdate()->findOrFail($delegation->id);
            $consumer = \App\Models\ApiConsumer::findOrFail($d->consumer_id);
            if ($consumer->kind !== 'native' || ! ConsumerRegistryState::active($consumer) || ! ConsumerRegistryState::active($d)) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $user = User::findOrFail($d->user_id);
            $caps = array_values(array_intersect(ConsumerRegistryState::capabilities($consumer->capabilities), ConsumerRegistryState::capabilities($d->capabilities)));
            if (! $caps || in_array('*', $caps, true)) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $new = $user->createToken('native-bound-renewal', $caps, $expires);
            $binding = app(ConsumerTokenBindingWriter::class)->bind($operator, $new->accessToken, $d, $caps, $expires);
            DB::table('api_consumer_lifecycle_events')->insert(['operator_id' => $operator->id, 'record_type' => 'binding', 'record_id' => $binding->id, 'action' => 'renew', 'approval_ref' => 'native-bound-renewal', 'created_at' => now()]);

            return ['token' => $new, 'binding' => $binding];
        });
    }
}

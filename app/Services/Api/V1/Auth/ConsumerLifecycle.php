<?php

namespace App\Services\Api\V1\Auth;

use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerCredential;
use App\Models\ApiConsumerDelegation;
use App\Models\ApiConsumerTokenBinding;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Internal administration only; no public routes, no default Gate and no credential in audit. */
final class ConsumerLifecycle
{
    public function transition(User $operator, Model $record, bool $enable, string $approvalRef): void
    {
        Gate::forUser($operator)->authorize('manageApiConsumerRegistry');
        $types = [ApiConsumer::class => 'consumer', ApiConsumerChannel::class => 'channel', ApiConsumerCredential::class => 'credential', ApiConsumerDelegation::class => 'delegation', ApiConsumerTokenBinding::class => 'binding'];
        if (! isset($types[$record::class]) || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $approvalRef)) {
            throw new \DomainException('Invalid administrative transition');
        }
        DB::transaction(function () use ($operator, $record, $enable, $approvalRef, $types) {
            $fresh = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            if ($enable && ($fresh->revoked_at || ! $fresh->expires_at?->isFuture())) {
                throw new \DomainException('Record cannot be activated');
            }
            $fresh->forceFill(['enabled' => $enable, 'revoked_at' => $enable ? null : now()])->save();
            DB::table('api_consumer_lifecycle_events')->insert(['operator_id' => $operator->id, 'record_type' => $types[$record::class], 'record_id' => $fresh->getKey(), 'action' => $enable ? 'activate' : 'revoke', 'approval_ref' => $approvalRef, 'created_at' => now()]);
        });
    }

    public function authorizeOtp(User $administrator, ApiConsumerChannel $channel, User $issuer, string $approvalRef, Carbon $expiresAt): void
    {
        Gate::forUser($administrator)->authorize('manageApiConsumerRegistry');
        $current = $channel->fresh();
        if (! Gate::forUser($issuer)->allows('manageApiConsumerRegistry') || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $approvalRef) || ! $expiresAt->isFuture() || $expiresAt->gt($current->expires_at)) {
            throw new \DomainException('Invalid OTP issuer authority');
        }
        DB::table('api_consumer_otp_issuers')->updateOrInsert(['channel_id' => $current->id], ['consumer_id' => $current->consumer_id, 'operator_id' => $issuer->id, 'enabled' => true, 'approval_ref' => $approvalRef, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
    }
}

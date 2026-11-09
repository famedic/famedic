<?php

namespace App\Services\MedicalCheckout;

use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerRegistryState;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class MedicalFundingGate
{
    public function authorize(BenefitActor $actor, ?User $recoveryOperator = null): void
    {
        if (! app(BenefitApprovals::class)->financial() || config('akubica_connectors.financial.enabled') !== true || config('akubica_connectors.financial.certified') !== true) {
            throw new BenefitFailure('FINANCIAL_WRITES_PENDING_APPROVAL');
        }
        if (config('akubica_connectors.financial.legacy_writers_coordinated') !== true || \App\Services\CouponApplicationService::RESERVATION_GUARD_VERSION !== 1 || ! Schema::hasTable('medical_purchase_intents')) {
            throw new BenefitFailure('LEGACY_CREDIT_WRITERS_NOT_COORDINATED');
        }
        $u = User::findOrFail($actor->userId);
        if ($recoveryOperator) {
            Gate::forUser($recoveryOperator)->authorize('reconcileMedicalBenefits', $actor);
        } else {
            Gate::forUser($u)->authorize('purchaseMedicalBenefits', $actor);
        }
        $c = ApiConsumer::find($actor->consumerId);
        $ch = ApiConsumerChannel::find($actor->channelId);
        $d = ApiConsumerDelegation::find($actor->delegationId);
        if (! $c || ! $ch || ! $d || (! $recoveryOperator && (! ConsumerRegistryState::active($c) || ! ConsumerRegistryState::active($ch) || ! ConsumerRegistryState::active($d))) || (int) $ch->consumer_id !== (int) $c->id || (int) $d->consumer_id !== (int) $c->id || (int) $d->channel_id !== (int) $ch->id || (int) $d->user_id !== (int) $u->id || (int) $d->customer_id !== (int) $u->customer?->id) {
            throw new BenefitFailure('CONSUMER_NOT_AUTHORIZED');
        }
    }
}

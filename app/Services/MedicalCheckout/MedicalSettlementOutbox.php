<?php

namespace App\Services\MedicalCheckout;

use App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction;
use App\Actions\MedicalAttention\UpdateStatusAction;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Durable, serialized downstream delivery. Unknown outcomes never auto-retry. No scheduled worker enabled. */
final class MedicalSettlementOutbox
{
    private function gate(User $operator, string $permission): void
    {
        if (config('akubica_connectors.outbox.enabled') !== true || config('akubica_connectors.outbox.certified') !== true) {
            throw new BenefitFailure('FULFILLMENT_DELIVERY_PENDING_CERTIFICATION');
        }
        Gate::forUser($operator)->authorize($permission);
    }

    public function deliver(User $operator, int $id): string
    {
        $this->gate($operator, 'dispatchMedicalSettlementOutbox');
        $lookup = DB::table('medical_settlement_outbox as o')->join('medical_purchase_intents as i', 'i.id', '=', 'o.intent_id')->where('o.id', $id)->first(['i.customer_id']);
        if (! $lookup) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        $delivery = DB::transaction(function () use ($lookup, $id, $operator) {
            Customer::whereKey($lookup->customer_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('medical_settlement_outbox')->where('id', $id)->lockForUpdate()->sole();
            $intent = DB::table('medical_purchase_intents')->where('id', $row->intent_id)->lockForUpdate()->sole();
            if (in_array($row->state, ['delivered', 'superseded'], true)) {
                return ['state' => $row->state];
            }
            if ($row->state !== 'pending') {
                throw new BenefitFailure('FULFILLMENT_DELIVERY_UNCERTAIN');
            }
            if (DB::table('medical_settlement_outbox as o')->join('medical_purchase_intents as i', 'i.id', '=', 'o.intent_id')->where('i.customer_id', $lookup->customer_id)->where('o.id', '<>', $id)->whereIn('o.state', ['dispatching', 'needs_review'])->exists()) {
                throw new BenefitFailure('FULFILLMENT_DELIVERY_IN_PROGRESS');
            }
            if (($row->kind === 'activate' && ($intent->state !== 'fulfilled' || $intent->reverse_started_at)) || ! $intent->membership_id) {
                DB::table('medical_settlement_outbox')->where('id', $id)->update(['state' => 'superseded', 'updated_at' => now()]);

                return ['state' => 'superseded'];
            }
            if (! in_array($row->kind, ['activate', 'reverse'], true) || ($row->kind === 'reverse' && $intent->state !== 'reversed')) {
                throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
            }
            $member = MedicalAttentionSubscription::withTrashed()->whereKey($intent->membership_id)->where('customer_id', $intent->customer_id)->firstOrFail();
            if ($row->kind === 'activate' && $member->trashed()) {
                DB::table('medical_settlement_outbox')->where('id', $id)->update(['state' => 'superseded', 'updated_at' => now()]);

                return ['state' => 'superseded'];
            }
            $status = $member->customer->medicalAttentionSubscriptions()->active()->exists() ? 'activo' : 'inactivo';
            DB::table('medical_settlement_outbox')->where('id', $id)->update(['state' => 'dispatching', 'claimed_at' => now(), 'operator_id' => $operator->id, 'updated_at' => now()]);

            return ['state' => 'dispatching', 'member' => $member, 'kind' => $row->kind, 'status' => $status];
        }, 3);
        if ($delivery['state'] !== 'dispatching') {
            return $delivery['state'];
        }
        try {
            $m = $delivery['member'];
            $ok = $delivery['kind'] === 'activate' ? app(SyncSubscriptionToMurguiaAction::class)($m, $delivery['status'], $m->start_date, $m->end_date) : app(UpdateStatusAction::class)($m->customer, $delivery['status'])->successful();
        } catch (\Throwable) {
            $ok = false;
        }
        $state = $ok ? 'delivered' : 'needs_review';
        DB::table('medical_settlement_outbox')->where('id', $id)->where('state', 'dispatching')->update(['state' => $state, 'delivered_at' => $ok ? now() : null, 'updated_at' => now()]);

        return $state;
    }

    public function certifyOutcome(User $operator, int $id, bool $delivered, string $approval): void
    {
        $this->gate($operator, 'reconcileMedicalSettlementOutbox');
        if (! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $approval)) {
            throw new \DomainException('Invalid administrative reference');
        }
        DB::transaction(function () use ($operator, $id, $delivered, $approval) {
            $row = DB::table('medical_settlement_outbox')->where('id', $id)->lockForUpdate()->sole();
            if (! in_array($row->state, ['dispatching', 'needs_review'], true)) {
                throw new BenefitFailure('FULFILLMENT_DELIVERY_NOT_UNCERTAIN');
            }
            DB::table('medical_settlement_outbox')->where('id', $id)->update(['state' => $delivered ? 'delivered' : 'pending', 'operator_id' => $operator->id, 'approval_ref' => $approval, 'delivered_at' => $delivered ? now() : null, 'updated_at' => now()]);
        });
    }
}

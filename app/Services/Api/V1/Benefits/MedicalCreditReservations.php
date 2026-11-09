<?php

namespace App\Services\Api\V1\Benefits;

use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Dormant reservation-before-payment proposal. No routes, jobs or gateway callbacks registered.
 * Expiry NEVER releases funds while a payment outcome is uncertain.
 */
final class MedicalCreditReservations
{
    public function __construct(private BenefitApprovals $approvals, private MedicalCreditSettlementBridge $bridge, private MedicalPaymentAuthority $payments, private ReservationReleaseAuthority $unpaid) {}

    private function gate(): void
    {
        if (! $this->approvals->financial()) {
            throw new BenefitFailure('FINANCIAL_WRITES_PENDING_APPROVAL');
        }
    }

    private function result(object $row, bool $replayed = false): array
    {
        return ['reservation_ref' => Crypt::decryptString($row->reference_ciphertext), 'state' => $row->state, 'amount_cents' => (int) $row->amount_cents, 'expires_at' => $row->expires_at, 'replayed' => $replayed];
    }

    public function reserve(BenefitActor $actor, string $cartRef, string $appRef, string $key): array
    {
        $this->gate();
        if (! preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $key)) {
            throw new BenefitFailure('IDEMPOTENCY_KEY_REQUIRED');
        }
        $hash = hash('sha256', $actor->namespace().'|'.$key);
        $request = hash('sha256', json_encode([$cartRef, $appRef], JSON_THROW_ON_ERROR));
        $prior = DB::table('medical_credit_reservations')->where('key_hash', $hash)->where('actor_hash', $actor->namespace())->first();
        $store = new EloquentBenefitStateStore($actor, $prior ? (int) $prior->context_id : null);

        return $store->transaction(function () use ($store, $actor, $cartRef, $appRef, $hash, $request) {
            $prior = DB::table('medical_credit_reservations')->where('key_hash', $hash)->lockForUpdate()->first();
            if ($prior) {
                if ($prior->actor_hash !== $actor->namespace() || $prior->request_hash !== $request) {
                    throw new BenefitFailure('IDEMPOTENCY_KEY_CONFLICT');
                }

                return $this->result($prior, true);
            }
            $cart = $store->get('carts', OpaqueReference::key($cartRef, 'crt'));
            $application = $store->get('applications', OpaqueReference::key($appRef, 'app'));
            if (! $cart || ! $application || ! $cart['active'] || $cart['application'] !== $appRef || $application['status'] !== 'applied' || ! in_array($application['kind'], ['credit', 'coupon'], true) || $application['post_revision'] !== $cart['revision']) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }
            $source = $store->get('sources', $application['source_id']);
            if (! $source || ! $source['active'] || hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)) !== $application['source_stamp']) {
                throw new BenefitFailure('CREDIT_NOT_ELIGIBLE');
            }
            $held = (int) DB::table('medical_credit_reservations')->where('source_id', (int) $application['source_id'])->whereIn('state', ['held', 'needs_review'])->sum('amount_cents');
            if ($application['kind'] === 'coupon' && $held > 0) {
                throw new BenefitFailure('INSUFFICIENT_UNRESERVED_CREDIT');
            }
            if ((int) $application['amount'] <= 0 || $application['amount'] > $source['remaining_cents'] - $held) {
                throw new BenefitFailure('INSUFFICIENT_UNRESERVED_CREDIT');
            }
            if (DB::table('medical_credit_reservations')->where('active_application_hash', OpaqueReference::key($appRef, 'app'))->exists()) {
                throw new BenefitFailure('APPLICATION_ALREADY_RESERVED');
            }
            $ref = OpaqueReference::issue('rsv');
            $id = DB::table('medical_credit_reservations')->insertGetId(['context_id' => $store->contextId(), 'source_id' => (int) $application['source_id'], 'actor_hash' => $actor->namespace(), 'key_hash' => $hash, 'request_hash' => $request, 'reference_hash' => OpaqueReference::key($ref, 'rsv'), 'reference_ciphertext' => Crypt::encryptString($ref), 'application_hash' => OpaqueReference::key($appRef, 'app'), 'active_application_hash' => OpaqueReference::key($appRef, 'app'), 'source_stamp' => $application['source_stamp'], 'cart_version_hash' => OpaqueReference::key($cart['version'], 'ver'), 'amount_cents' => $application['amount'], 'subtotal_cents' => $cart['subtotal_cents'], 'state' => 'held', 'expires_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now()]);

            return $this->result(DB::table('medical_credit_reservations')->find($id));
        });
    }

    private function transaction(BenefitActor $actor, string $ref, callable $op): array
    {
        $this->gate();
        $row = DB::table('medical_credit_reservations')->where('reference_hash', OpaqueReference::key($ref, 'rsv'))->where('actor_hash', $actor->namespace())->first();
        if (! $row) {
            throw new BenefitFailure('RESERVATION_NOT_FOUND');
        }$store = new EloquentBenefitStateStore($actor, (int) $row->context_id);

        return $store->transaction(function () use ($row, $store, $op) {
            $row = DB::table('medical_credit_reservations')->where('id', $row->id)->lockForUpdate()->first();

            return $op($row, $store);
        });
    }

    public function assertChargeable(BenefitActor $actor, string $ref): void
    {
        $this->transaction($actor, $ref, function ($row, $store) {
            if ($row->state !== 'held' || now()->gte($row->expires_at)) {
                throw new BenefitFailure('RESERVATION_NOT_ACTIVE');
            }
            $cart = $store->get('carts', OpaqueReference::key($store->cartReference(), 'crt'));
            $application = $store->get('applications', $row->application_hash);
            $source = $store->get('sources', (string) $row->source_id);
            if (! $cart || ! $cart['active'] || ! $application || $application['status'] !== 'applied' || $cart['application'] !== $application['reference'] || $application['post_revision'] !== $cart['revision'] || OpaqueReference::key($cart['version'], 'ver') !== $row->cart_version_hash || ! $source || ! $source['active'] || $row->source_stamp !== hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }

            return [];
        });
    }

    public function bindPayment(BenefitActor $actor, string $ref, int $paymentId): array
    {
        return $this->transaction($actor, $ref, function ($row, $store) use ($actor, $paymentId) {
            if ($row->state !== 'held' || now()->gte($row->expires_at)) {
                throw new BenefitFailure('RESERVATION_NOT_ACTIVE');
            }
            if ($row->payment_transaction_id !== null) {
                if ((int) $row->payment_transaction_id !== $paymentId) {
                    throw new BenefitFailure('PAYMENT_BINDING_CONFLICT');
                }

                return $this->result($row, true);
            }
            $tx = Transaction::query()->lockForUpdate()->find($paymentId);
            $customer = User::findOrFail($actor->userId)->customer;
            if (! $tx || ! $customer || (int) ($tx->details['customer_id'] ?? 0) !== (int) $customer->id || (int) $tx->transaction_amount_cents !== (int) $row->subtotal_cents - (int) $row->amount_cents || ! in_array($tx->payment_status, ['pending', 'created', 'authorized'], true)) {
                throw new BenefitFailure('PAYMENT_BINDING_CONFLICT');
            }
            DB::table('medical_credit_reservations')->where('id', $row->id)->update(['payment_transaction_id' => $paymentId, 'updated_at' => now()]);

            return $this->result(DB::table('medical_credit_reservations')->find($row->id));
        });
    }

    public function confirmAndDebit(BenefitActor $actor, string $ref): array
    {
        return $this->transaction($actor, $ref, function ($row, $store) use ($actor) {
            if ($row->state === 'committed') {
                if (DB::table('opaque_benefit_movements')->where('settlement_id', $row->settlement_id)->where('kind', 'reversal')->exists()) {
                    throw new BenefitFailure('PURCHASE_ALREADY_REVERSED');
                }

                return $this->result($row, true);
            }
            if ($row->state !== 'held' || now()->gte($row->expires_at)) {
                throw new BenefitFailure('RESERVATION_REQUIRES_RECONCILIATION');
            }
            if (! $row->payment_transaction_id) {
                throw new BenefitFailure('PAYMENT_NOT_BOUND');
            }
            $application = $store->get('applications', $row->application_hash);
            $cart = $store->get('carts', OpaqueReference::key($store->cartReference(), 'crt'));
            $source = $store->get('sources', (string) $row->source_id);
            if (! $application || ! $source || $row->source_stamp !== hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)) || OpaqueReference::key($cart['version'], 'ver') !== $row->cart_version_hash) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }
            $purchase = $this->bridge->prepare($actor, $store->cartReference(), $application['reference'], (int) $row->payment_transaction_id, $store);
            $settlement = DB::table('opaque_benefit_settlements')->where('purchase_ref_hash', OpaqueReference::key($purchase, 'pur'))->sole();
            DB::table('medical_credit_reservations')->where('id', $row->id)->update(['settlement_id' => $settlement->id, 'purchase_ref_ciphertext' => Crypt::encryptString($purchase), 'updated_at' => now()]);
            $this->bridge->finalize($actor, $purchase, $store);
            DB::table('medical_credit_reservations')->where('id', $row->id)->update(['state' => 'committed', 'updated_at' => now()]);

            return $this->result(DB::table('medical_credit_reservations')->find($row->id));
        });
    }

    public function release(BenefitActor $actor, string $ref): array
    {
        return $this->transaction($actor, $ref, function ($row, $store) use ($actor) {
            if ($row->state === 'released') {
                return $this->result($row, true);
            }if (! in_array($row->state, ['held', 'needs_review'], true)) {
                throw new BenefitFailure('RESERVATION_NOT_RELEASABLE');
            }
            if ($row->payment_transaction_id) {
                $tx = Transaction::query()->lockForUpdate()->findOrFail($row->payment_transaction_id);
                $customer = User::findOrFail($actor->userId)->customer;
                if (! in_array($tx->payment_status, ['failed', 'cancelled', 'declined'], true) || ! $this->unpaid->definitivelyUnpaid($tx, (int) $customer->id)) {
                    throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
                }
            }
            DB::table('medical_credit_reservations')->where('id', $row->id)->update(['state' => 'released', 'active_application_hash' => null, 'updated_at' => now()]);

            return $this->result(DB::table('medical_credit_reservations')->find($row->id));
        });
    }

    public function markExpired(BenefitActor $actor, string $ref): array
    {
        return $this->transaction($actor, $ref, function ($row, $store) {
            if ($row->state === 'held' && now()->gte($row->expires_at)) {
                DB::table('medical_credit_reservations')->where('id', $row->id)->update(['state' => 'needs_review', 'updated_at' => now()]);
            }

            return $this->result(DB::table('medical_credit_reservations')->find($row->id));
        });
    }

    public function compensate(BenefitActor $actor, string $ref): array
    {
        // Committed debit reversal stays in the bridge's independent root transaction;
        // an atomic reservation transition is completed in a separate idempotent transaction.
        $this->gate();
        $row = DB::table('medical_credit_reservations')->where('reference_hash', OpaqueReference::key($ref, 'rsv'))->where('actor_hash', $actor->namespace())->first();
        if (! $row) {
            throw new BenefitFailure('RESERVATION_NOT_FOUND');
        }
        if ($row->state === 'committed') {
            $this->bridge->reverse($actor, Crypt::decryptString($row->purchase_ref_ciphertext));
        }

        return $this->transaction($actor, $ref, function ($row, $store) use ($actor) {
            if ($row->state === 'compensated') {
                return $this->result($row, true);
            }
            if ($row->state === 'committed') {
                if (! DB::table('opaque_benefit_movements')->where('settlement_id', $row->settlement_id)->where('kind', 'reversal')->exists()) {
                    throw new BenefitFailure('REVERSAL_NOT_VERIFIED');
                }
            } elseif (in_array($row->state, ['held', 'needs_review'], true) && $row->payment_transaction_id) {
                $tx = Transaction::query()->lockForUpdate()->findOrFail($row->payment_transaction_id);
                if ($tx->payment_status !== 'refunded' || ! $this->payments->reversed($tx, (int) User::findOrFail($actor->userId)->customer->id)) {
                    throw new BenefitFailure('REVERSAL_NOT_VERIFIED');
                }
            } else {
                throw new BenefitFailure('RESERVATION_NOT_RELEASABLE');
            }
            DB::table('medical_credit_reservations')->where('id', $row->id)->update(['state' => 'compensated', 'active_application_hash' => null, 'updated_at' => now()]);

            return $this->result(DB::table('medical_credit_reservations')->find($row->id));
        });
    }
}

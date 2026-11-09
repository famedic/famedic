<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Actions\Transactions\CreateCouponBalanceTransactionAction;
use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\BenefitsAuthorizer;
use App\Domain\Akubica\Benefits\BenefitStateStore;
use App\Domain\Akubica\Benefits\MedicalPurchaseVerifier;
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Domain\Akubica\Benefits\PartialCreditFinalizer;
use App\Domain\Akubica\Benefits\VerifiedMedicalDebit;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Dormant adapter for a trusted medical checkout hook. No route/event registration.
 * Approval and independently verified payment are required on every invocation.
 * Existing full-price PayPal fulfillment must not call this adapter until adapted.
 */
final class MedicalCreditSettlementBridge
{
    public function __construct(private BenefitApprovals $approvals, private MedicalPaymentAuthority $authority) {}

    private function gate(): void
    {
        if (! $this->approvals->financial()) {
            throw new BenefitFailure('FINANCIAL_WRITES_PENDING_APPROVAL');
        }
    }

    private function payment(object $settlement, BenefitActor $actor, bool $reverse = false): Transaction
    {
        $payment = Transaction::query()->lockForUpdate()->find($settlement->transaction_id);
        $customer = User::findOrFail($actor->userId)->customer;
        if (! $payment || ! $customer || $payment->isSimulated() || (! $payment->medicalAttentionSubscriptions()->where('customer_id', $customer->id)->exists() && ! \App\Services\MedicalCheckout\RecordedMedicalPaymentAuthority::hasIntent($payment, (int) $customer->id))) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        $cash = (int) $settlement->subtotal_cents - (int) $settlement->credit_cents;
        if ($cash < 0 || (int) $payment->transaction_amount_cents !== $cash) {
            throw new BenefitFailure('PURCHASE_AMOUNT_CONFLICT');
        }
        if ($reverse) {
            if ($payment->payment_status !== 'refunded' || ! $this->authority->reversed($payment, (int) $customer->id)) {
                throw new BenefitFailure('REVERSAL_NOT_VERIFIED');
            }
        } elseif (! in_array($payment->payment_status, ['captured', 'completed', 'paid', 'succeeded', 'internal_funding'], true) || ! $this->authority->confirmed($payment, (int) $customer->id, $cash)) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }

        return $payment;
    }

    public function prepare(BenefitActor $actor, string $cartRef, string $applicationRef, int $transactionId, ?EloquentBenefitStateStore $scope = null): string
    {
        $this->gate();
        $store = $scope ?? new EloquentBenefitStateStore($actor);
        if ($store->actor->namespace() !== $actor->namespace()) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }

        return $store->transaction(function () use ($store, $actor, $cartRef, $applicationRef, $transactionId) {
            $cart = $store->get('carts', OpaqueReference::key($cartRef, 'crt'));
            $application = $store->get('applications', OpaqueReference::key($applicationRef, 'app'));
            if (! $cart || ! $application || ! $cart['active'] || $cart['application'] !== $applicationRef || ! in_array($application['kind'], ['credit', 'coupon'], true) || $application['status'] !== 'applied' || $application['post_revision'] !== $cart['revision']) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }
            $source = $store->get('sources', $application['source_id']);
            if (! $source || ! $source['active'] || $application['source_stamp'] !== hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))) {
                throw new BenefitFailure('CREDIT_NOT_ELIGIBLE');
            }
            $row = (object) ['transaction_id' => $transactionId, 'subtotal_cents' => $cart['subtotal_cents'], 'credit_cents' => $application['amount']];
            $this->payment($row, $actor);
            if (DB::table('opaque_benefit_settlements')->where('transaction_id', $transactionId)->exists()) {
                throw new BenefitFailure('PURCHASE_ALREADY_BOUND');
            }
            $ref = OpaqueReference::issue('pur');
            DB::table('opaque_benefit_settlements')->insert(['context_id' => $store->contextId(), 'transaction_id' => $transactionId, 'source_id' => (int) $application['source_id'], 'purchase_ref_hash' => OpaqueReference::key($ref, 'pur'), 'credit_cents' => $application['amount'], 'subtotal_cents' => $cart['subtotal_cents'], 'application_hash' => OpaqueReference::key($applicationRef, 'app'), 'source_stamp' => $application['source_stamp'], 'created_at' => now(), 'updated_at' => now()]);

            return $ref;
        });
    }

    private function context(BenefitActor $actor, string $ref, ?EloquentBenefitStateStore $scope = null): array
    {
        $row = DB::table('opaque_benefit_settlements')->join('opaque_benefit_contexts', 'opaque_benefit_contexts.id', '=', 'opaque_benefit_settlements.context_id')->where('purchase_ref_hash', OpaqueReference::key($ref, 'pur'))->where('actor_hash', $actor->namespace())->first(['opaque_benefit_settlements.*']);
        if (! $row) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }

        if ($scope && ($scope->actor->namespace() !== $actor->namespace() || $scope->contextId() !== (int) $row->context_id)) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }

        return [$row, $scope ?? new EloquentBenefitStateStore($actor, (int) $row->context_id)];
    }

    public function finalize(BenefitActor $actor, string $ref, ?EloquentBenefitStateStore $scope = null): array
    {
        $this->gate();
        [$row,$store] = $this->context($actor, $ref, $scope);

        return $store->transaction(function () use ($row, $store, $actor, $ref) {
            $row = DB::table('opaque_benefit_settlements')->where('id', $row->id)->lockForUpdate()->first();
            $this->payment($row, $actor);
            if (DB::table('opaque_benefit_movements')->where('settlement_id', $row->id)->where('kind', 'reversal')->exists()) {
                throw new BenefitFailure('PURCHASE_ALREADY_REVERSED');
            }
            $prior = DB::table('opaque_benefit_movements')->where('settlement_id', $row->id)->where('kind', 'debit')->first();
            if ($prior) {
                return ['debited_cents' => (int) $prior->amount_cents, 'remaining_cents' => (int) $prior->remaining_after, 'replayed' => true];
            }
            $application = $store->get('applications', $row->application_hash);
            $source = $store->get('sources', (string) $row->source_id);
            if (! $application || $application['status'] !== 'applied' || ! $source || $row->source_stamp !== hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }
            // Recheck current selected application and immutable revision, including legacy writers.
            $current = $store->get('carts', OpaqueReference::key($store->cartReference(), 'crt'));
            if ($current['application'] !== $application['reference'] || $application['post_revision'] !== $current['revision'] || (int) $row->subtotal_cents !== $current['subtotal_cents']) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }
            $write = function (string $key, array $value) use ($row, $actor, $application) {
                if ($key !== (string) $row->source_id) {
                    throw new BenefitFailure('PURCHASE_DEBIT_CONFLICT');
                }
                $coupon = Coupon::findOrFail($row->source_id);
                $before = (int) $coupon->remaining_cents;
                $after = $value['remaining_cents'];
                if (($application['kind'] === 'credit' && $before - $after !== (int) $row->credit_cents) || ($application['kind'] === 'coupon' && ($after !== 0 || $before < (int) $row->credit_cents)) || $after < 0) {
                    throw new BenefitFailure('PURCHASE_DEBIT_CONFLICT');
                }
                $held = DB::table('medical_credit_reservations')->where('source_id', $row->source_id)->whereIn('state', ['held', 'needs_review'])->where(function ($q) use ($row) {
                    $q->whereNull('settlement_id')->orWhere('settlement_id', '<>', $row->id);
                })->sum('amount_cents');
                if ($after < (int) $held) {
                    throw new BenefitFailure('RESERVED_CREDIT_CONFLICT');
                }
                $coupon->update(['remaining_cents' => $after]);
                $assignment = CouponUser::where('coupon_id', $coupon->id)->where('user_id', $actor->userId)->sole();
                if ($after === 0) {
                    $assignment->update(['used_at' => now()]);
                }
                $credit = $application['kind'] === 'credit' ? app(CreateCouponBalanceTransactionAction::class)(User::findOrFail($actor->userId)->customer, (int) $coupon->id, (int) $row->credit_cents) : null;
                DB::table('opaque_benefit_movements')->insert(['settlement_id' => $row->id, 'source_id' => $coupon->id, 'credit_transaction_id' => $credit?->id, 'benefit_kind' => $application['kind'], 'kind' => 'debit', 'amount_cents' => $row->credit_cents, 'remaining_before' => $before, 'remaining_after' => $after, 'source_revision_after' => (int) $coupon->fresh()->opaque_revision, 'assignment_revision_after' => (int) $assignment->fresh()->opaque_revision, 'created_at' => now()]);
            };
            if ($application['kind'] === 'coupon') {
                if ($source['kind'] !== 'coupon' || ! $source['active'] || (int) $row->credit_cents !== min((int) $source['remaining_cents'], (int) $row->subtotal_cents)) {
                    throw new BenefitFailure('CREDIT_NOT_ELIGIBLE');
                }
                $write((string) $row->source_id, ['remaining_cents' => 0]);

                return ['discount_applied_cents' => (int) $row->credit_cents, 'remaining_cents' => 0, 'replayed' => false];
            }
            $adapter = new class($store, $write) implements BenefitStateStore
            {
                public function __construct(private BenefitStateStore $store, private \Closure $write) {}

                public function transaction(callable $op): mixed
                {
                    return $this->store->transaction($op);
                }

                public function get(string $table, string $key): ?array
                {
                    return $this->store->get($table, $key);
                }

                public function all(string $table): array
                {
                    return $this->store->all($table);
                }

                public function put(string $table, string $key, array $value): void
                {
                    if ($table === 'sources') {
                        ($this->write)($key, $value);

                        return;
                    }$this->store->put($table, $key, $value);
                }
            };
            $auth = new class($actor) implements BenefitsAuthorizer
            {
                public function __construct(private BenefitActor $actor) {}

                public function authorize(BenefitActor $actor, string $cap, int $now): void
                {
                    if ($actor->namespace() !== $this->actor->namespace() || $cap !== 'benefits:finalize') {
                        throw new BenefitFailure('CONSUMER_NOT_AUTHORIZED');
                    }
                }
            };
            $verifier = new class($ref, $actor, $row) implements MedicalPurchaseVerifier
            {
                public function __construct(private string $ref, private BenefitActor $actor, private object $row) {}

                public function verifiedDebit(BenefitActor $actor, string $ref): ?VerifiedMedicalDebit
                {
                    return $ref === $this->ref && $actor->namespace() === $this->actor->namespace() ? new VerifiedMedicalDebit($ref, $actor->userId, (string) $this->row->source_id, (int) $this->row->credit_cents) : null;
                }
            };

            return (new PartialCreditFinalizer($adapter, $auth, $verifier))->finalize($actor, $ref, now()->timestamp);
        });
    }

    public function reverse(BenefitActor $actor, string $ref): array
    {
        $this->gate();
        [$row,$store] = $this->context($actor, $ref);

        return $store->transaction(function () use ($row, $actor) {
            $row = DB::table('opaque_benefit_settlements')->where('id', $row->id)->lockForUpdate()->first();
            $this->payment($row, $actor, true);
            $prior = DB::table('opaque_benefit_movements')->where('settlement_id', $row->id)->where('kind', 'reversal')->first();
            if ($prior) {
                return ['restored_cents' => (int) $prior->amount_cents, 'replayed' => true];
            }
            $debit = DB::table('opaque_benefit_movements')->where('settlement_id', $row->id)->where('kind', 'debit')->first();
            if (! $debit) {
                throw new BenefitFailure('DEBIT_NOT_FOUND');
            }
            $coupon = Coupon::findOrFail($row->source_id);
            $assignment = CouponUser::where('coupon_id', $coupon->id)->where('user_id', $actor->userId)->sole();
            if ((int) $coupon->remaining_cents !== (int) $debit->remaining_after || (int) $coupon->opaque_revision !== (int) $debit->source_revision_after || (int) $assignment->opaque_revision !== (int) $debit->assignment_revision_after) {
                throw new BenefitFailure('RECONCILIATION_REQUIRED');
            }
            $coupon->update(['remaining_cents' => (int) $debit->remaining_before]);
            if ((int) $debit->remaining_after === 0) {
                $assignment->update(['used_at' => null]);
            }
            DB::table('opaque_benefit_movements')->insert(['settlement_id' => $row->id, 'source_id' => $coupon->id, 'kind' => 'reversal', 'benefit_kind' => $debit->benefit_kind, 'amount_cents' => $debit->amount_cents, 'remaining_before' => $debit->remaining_after, 'remaining_after' => $debit->remaining_before, 'source_revision_after' => (int) $coupon->fresh()->opaque_revision, 'assignment_revision_after' => (int) $assignment->fresh()->opaque_revision, 'created_at' => now()]);

            return ['restored_cents' => (int) $debit->amount_cents, 'replayed' => false];
        });
    }
}

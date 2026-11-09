<?php

namespace App\Services\MedicalCheckout;

use App\Actions\MedicalAttention\CreateRegularSubscriptionAction;
use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Services\Api\V1\Benefits\MedicalCreditReservations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Durable reserve -> provider -> verified evidence -> debit -> fulfillment.
 * No public route or worker registration. Provider I/O is always outside DB transactions.
 */
final class MedicalPurchaseSaga
{
    private ?\App\Models\User $recoveryOperator = null;

    public function __construct(private MedicalFundingGate $gate, private MedicalCreditReservations $reservations, private MedicalGatewayRegistry $gateways) {}

    private function row(BenefitActor $actor, string $ref): object
    {
        $this->gate->authorize($actor, $this->recoveryOperator);
        $r = DB::table('medical_purchase_intents')->where('reference', $ref)->where('actor_hash', $actor->namespace())->first();
        if (! $r) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }

        return $r;
    }

    private function result(object $row): array
    {
        return ['purchase_ref' => $row->reference, 'state' => $row->state, 'subtotal_cents' => (int) $row->subtotal_cents, 'credit_cents' => (int) $row->credit_cents, 'discount_cents' => (int) $row->discount_cents, 'benefit_type' => $row->benefit_kind, 'cash_cents' => (int) $row->cash_cents, 'currency' => 'MXN'];
    }

    public function status(BenefitActor $actor, string $ref): array
    {
        return $this->result($this->row($actor, $ref));
    }

    public function begin(BenefitActor $actor, string $cart, string $application, string $key, string $provider): array
    {
        $this->gate->authorize($actor);
        if (! in_array($provider, ['paypal', 'efevoopay', 'odessa'], true)) {
            throw new BenefitFailure('GATEWAY_NOT_SUPPORTED');
        }
        $held = $this->reservations->reserve($actor, $cart, $application, $key);

        return DB::transaction(function () use ($actor, $held, $key, $provider) {
            $r = DB::table('medical_credit_reservations')->where('reference_hash', OpaqueReference::key($held['reservation_ref'], 'rsv'))->lockForUpdate()->sole();
            $prior = DB::table('medical_purchase_intents')->where('reservation_id', $r->id)->first();
            if ($prior) {
                if ($prior->provider !== $provider) {
                    throw new BenefitFailure('IDEMPOTENCY_KEY_CONFLICT');
                }

                return $this->result($prior);
            }
            if ($r->state !== 'held' || now()->gte($r->expires_at)) {
                throw new BenefitFailure('RESERVATION_NOT_ACTIVE');
            }
            if ((int) $r->subtotal_cents !== (int) config('famedic.medical_attention_subscription_price_cents')) {
                throw new BenefitFailure('PURCHASE_AMOUNT_CONFLICT');
            }
            $ref = OpaqueReference::issue('pmt');
            $customer = \App\Models\User::findOrFail($actor->userId)->customer;
            if ($customer->medicalAttentionSubscriptions()->active()->exists()) {
                throw new BenefitFailure('MEMBERSHIP_ALREADY_ACTIVE');
            }
            $cash = (int) $r->subtotal_cents - (int) $r->amount_cents;
            $source = \App\Models\Coupon::findOrFail($r->source_id);
            $kind = $source->type === \App\Enums\CouponType::Balance ? 'credit' : 'coupon';
            $tx = Transaction::create(['transaction_amount_cents' => $cash, 'payment_method' => $cash === 0 ? ($kind === 'credit' ? 'coupon_balance' : 'coupon_discount') : $provider, 'gateway' => $cash === 0 ? 'internal_benefit' : $provider, 'payment_status' => 'pending', 'reference_id' => $ref, 'details' => ['customer_id' => $customer->id, 'purpose' => 'medical_benefit_settlement']]);
            $id = DB::table('medical_purchase_intents')->insertGetId(['customer_id' => $customer->id, 'reservation_id' => $r->id, 'transaction_id' => $tx->id, 'actor_hash' => $actor->namespace(), 'key_hash' => hash('sha256', $actor->namespace().'|'.$key), 'reference' => $ref, 'provider' => $provider, 'state' => 'reserved', 'subtotal_cents' => $r->subtotal_cents, 'benefit_kind' => $kind, 'credit_cents' => $kind === 'credit' ? $r->amount_cents : 0, 'discount_cents' => $kind === 'coupon' ? $r->amount_cents : 0, 'cash_cents' => $cash, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('medical_credit_reservations')->where('id', $r->id)->update(['payment_transaction_id' => $tx->id, 'updated_at' => now()]);

            return $this->result(DB::table('medical_purchase_intents')->find($id));
        });
    }

    public function start(BenefitActor $actor, string $ref, string $paymentMethod = ''): array
    {
        $row = $this->row($actor, $ref);
        if ($row->state !== 'reserved') {
            return $this->result($row);
        }
        $reservation = DB::table('medical_credit_reservations')->find($row->reservation_id);
        try {
            $this->reservations->assertChargeable($actor, Crypt::decryptString($reservation->reference_ciphertext));
        } catch (BenefitFailure $e) {
            $current = $this->row($actor, $ref);
            if ($current->state !== 'reserved') {
                return $this->result($current);
            }
            throw $e;
        }
        $gateway = (int) $row->cash_cents > 0 ? $this->gateways->get($row->provider) : null;
        if (DB::table('medical_purchase_intents')->where('id', $row->id)->where('state', 'reserved')->update(['state' => 'charging', 'updated_at' => now()]) !== 1) {
            return $this->result($this->row($actor, $ref));
        }
        $tx = Transaction::findOrFail($row->transaction_id);
        try {
            $observation = $gateway ? $gateway->start(Customer::findOrFail($row->customer_id), $tx, $ref, (int) $row->cash_cents, $paymentMethod) : new PaymentObservation('confirmed', $ref, 0, 'internal-benefit-funding-'.$row->id);
        } catch (\Throwable) {
            $this->uncertain($row);
            throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
        }
        $this->record($row, $observation);

        return $observation->outcome === 'confirmed' ? $this->settle($actor, $ref) : $this->result($this->row($actor, $ref));
    }

    private function uncertain(object $r): void
    {
        DB::transaction(function () use ($r) {
            DB::table('medical_purchase_intents')->where('id', $r->id)->whereIn('state', ['charging', 'awaiting_payment', 'needs_review', 'reversing'])->update(['state' => 'needs_review', 'updated_at' => now()]);
            DB::table('medical_credit_reservations')->where('id', $r->reservation_id)->where('state', 'held')->update(['state' => 'needs_review', 'updated_at' => now()]);
        });
    }

    private function record(object $r, PaymentObservation $o): void
    {
        if ($o->reference !== $r->reference || $o->cashCents !== (int) $r->cash_cents || $o->currency !== 'MXN' || ! in_array($o->outcome, ['confirmed', 'pending', 'unknown', 'reversed', 'unpaid'], true)) {
            $this->uncertain($r);
            throw new BenefitFailure('PURCHASE_AMOUNT_CONFLICT');
        }
        if ($o->outcome === 'unknown') {
            $this->uncertain($r);

            return;
        }
        DB::transaction(function () use ($r, $o) {
            $current = DB::table('medical_purchase_intents')->where('id', $r->id)->lockForUpdate()->sole();
            if ($o->outcome === 'pending') {
                if (! $o->providerOrder) {
                    throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
                }
                Transaction::whereKey($r->transaction_id)->update(['provider_order_id' => $o->providerOrder]);
                DB::table('medical_purchase_intents')->where('id', $r->id)->update(['state' => 'awaiting_payment', 'updated_at' => now()]);

                return;
            }
            if ($o->event === '') {
                throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
            }
            $event = hash('sha256', $o->event);
            $prior = DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', $o->outcome)->first();
            if ($prior && ($prior->event_hash !== $event || (int) $prior->cash_cents !== $o->cashCents)) {
                throw new BenefitFailure('PROVIDER_EVENT_CONFLICT');
            }
            if (! $prior) {
                DB::table('medical_payment_verifications')->insert(['intent_id' => $r->id, 'kind' => $o->outcome, 'provider' => $r->provider, 'event_hash' => $event, 'cash_cents' => $o->cashCents, 'currency' => $o->currency, 'created_at' => now()]);
            }
            if ($o->outcome === 'confirmed') {
                if ($current->reverse_started_at) {
                    throw new BenefitFailure('REVERSAL_NOT_VERIFIED');
                }
                Transaction::whereKey($r->transaction_id)->update(['payment_status' => $o->cashCents === 0 ? 'internal_funding' : 'captured', 'gateway_transaction_id' => $o->cashCents === 0 ? null : $o->event]);
                DB::table('medical_purchase_intents')->where('id', $r->id)->whereNotIn('state', ['debited', 'fulfilled', 'reversed'])->update(['state' => 'confirmed', 'updated_at' => now()]);
            } elseif ($o->outcome === 'reversed') {
                Transaction::whereKey($r->transaction_id)->update(['payment_status' => 'refunded']);
                DB::table('medical_purchase_intents')->where('id', $r->id)->where('state', '<>', 'reversed')->update(['state' => 'reversing', 'reverse_started_at' => $current->reverse_started_at ?? now(), 'updated_at' => now()]);
            }
        });
    }

    public function reconcile(BenefitActor $actor, string $ref): array
    {
        $r = $this->row($actor, $ref);
        if (in_array($r->state, ['fulfilled', 'reversed', 'cancelled'], true)) {
            return $this->result($r);
        }
        if (in_array($r->state, ['confirmed', 'debited'], true)) {
            return $this->settle($actor, $ref);
        }
        if ($r->reverse_started_at) {
            if (! DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', 'reversed')->exists() && $r->provider === 'efevoopay') {
                $observation = $this->gateways->get($r->provider)->observe(Customer::findOrFail($r->customer_id), Transaction::findOrFail($r->transaction_id), $ref, (int) $r->cash_cents);
                if ($observation->outcome === 'reversed') {
                    $this->record($r, $observation);
                }
            }
            if (DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', 'reversed')->exists()) {
                return $this->reverse($actor, $ref);
            }

            return $this->result($r);
        }
        if ($r->state === 'reserved') {
            return $this->result($r);
        }
        if ((int) $r->cash_cents === 0) {
            $this->uncertain($r);

            return $this->result($this->row($actor, $ref));
        }
        try {
            $o = $this->gateways->get($r->provider)->observe(Customer::findOrFail($r->customer_id), Transaction::findOrFail($r->transaction_id), $ref, (int) $r->cash_cents);
        } catch (\Throwable) {
            $this->uncertain($r);
            throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
        }
        $this->record($r, $o);

        return $o->outcome === 'confirmed' ? $this->settle($actor, $ref) : $this->result($this->row($actor, $ref));
    }

    private function settle(BenefitActor $actor, string $ref): array
    {
        $r = $this->row($actor, $ref);
        if ($r->state === 'fulfilled') {
            return $this->result($r);
        }
        if (! in_array($r->state, ['confirmed', 'debited'], true)) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        $reservation = DB::table('medical_credit_reservations')->find($r->reservation_id);
        $hold = Crypt::decryptString($reservation->reference_ciphertext);
        // Late confirmation is reconciled only after authoritative proof, while funds remained held.
        if ($reservation->state === 'needs_review' || ($reservation->state === 'held' && now()->gte($reservation->expires_at))) {
            if (! app(RecordedMedicalPaymentAuthority::class)->confirmed(Transaction::findOrFail($r->transaction_id), (int) $r->customer_id, (int) $r->cash_cents)) {
                throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
            }
            DB::table('medical_credit_reservations')->where('id', $reservation->id)->whereIn('state', ['held', 'needs_review'])->update(['state' => 'held', 'expires_at' => now()->addMinutes(2), 'updated_at' => now()]);
        }
        $this->reservations->confirmAndDebit($actor, $hold);
        DB::table('medical_purchase_intents')->where('id', $r->id)->where('state', 'confirmed')->update(['state' => 'debited', 'updated_at' => now()]);
        DB::transaction(function () use ($r) {
            $customer = Customer::whereKey($r->customer_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('medical_purchase_intents')->where('id', $r->id)->lockForUpdate()->sole();
            if ($current->state === 'fulfilled') {
                return;
            }
            if ($current->state !== 'debited' || $customer->medicalAttentionSubscriptions()->active()->exists()) {
                throw new BenefitFailure('MEMBERSHIP_ALREADY_ACTIVE');
            }
            $member = app(CreateRegularSubscriptionAction::class)($customer, null, null, false, (int) $r->subtotal_cents);
            $member->transactions()->attach($r->transaction_id);
            $customer->update(['medical_attention_subscription_expires_at' => $member->end_date]);
            DB::table('medical_purchase_intents')->where('id', $r->id)->update(['membership_id' => $member->id, 'state' => 'fulfilled', 'updated_at' => now()]);
            DB::table('medical_settlement_outbox')->insert(['intent_id' => $r->id, 'kind' => 'activate', 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        });

        return $this->result($this->row($actor, $ref));
    }

    public function reconcileTransaction(Transaction $transaction): array
    {
        $r = DB::table('medical_purchase_intents')->where('transaction_id', $transaction->id)->first();
        if (! $r) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        $c = DB::table('medical_credit_reservations as r')->join('opaque_benefit_contexts as c', 'c.id', '=', 'r.context_id')->where('r.id', $r->reservation_id)->first(['c.user_id', 'c.consumer_id', 'c.channel_id', 'c.delegation_id']);
        $actor = new BenefitActor((int) $c->user_id, (string) $c->consumer_id, (string) $c->delegation_id, (string) $c->channel_id);

        return $this->reconcile($actor, $r->reference);
    }

    public function capturePayPal(Transaction $tx, Customer $customer): array
    {
        $r = DB::table('medical_purchase_intents')->where('transaction_id', $tx->id)->where('customer_id', $customer->id)->where('provider', 'paypal')->first();
        if (! $r) {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        // Apply the same authoritative actor gate before any provider side effect.
        $c = DB::table('medical_credit_reservations as r')->join('opaque_benefit_contexts as c', 'c.id', '=', 'r.context_id')->where('r.id', $r->reservation_id)->first(['c.user_id', 'c.consumer_id', 'c.channel_id', 'c.delegation_id']);
        $actor = new BenefitActor((int) $c->user_id, (string) $c->consumer_id, (string) $c->delegation_id, (string) $c->channel_id);
        $this->gate->authorize($actor);
        $this->gateways->get('paypal');
        if ($r->state === 'awaiting_payment') {
            $reservation = DB::table('medical_credit_reservations')->find($r->reservation_id);
            $this->reservations->assertChargeable($actor, Crypt::decryptString($reservation->reference_ciphertext));
        }
        if ($r->state === 'awaiting_payment' && DB::table('medical_purchase_intents')->where('id', $r->id)->where('state', 'awaiting_payment')->update(['state' => 'capture_requested', 'updated_at' => now()]) === 1) {
            try {
                app(\App\Services\PayPalService::class)->captureOrder($tx->provider_order_id);
            } catch (\Throwable) {
                $this->uncertain($r);
            }
        }

        return $this->reconcile($actor, $r->reference);
    }

    public function reverse(BenefitActor $actor, string $ref): array
    {
        $r = $this->row($actor, $ref);
        \Illuminate\Support\Facades\Gate::forUser($this->recoveryOperator ?? \App\Models\User::findOrFail($actor->userId))->authorize('reverseMedicalBenefits', [$actor, $ref]);
        if ($r->state === 'reversed') {
            return $this->result($r);
        }
        if (($r->state === 'reversing' || $r->state === 'needs_review') && ! DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', 'reversed')->exists()) {
            return $this->result($r);
        }
        if (! in_array($r->state, ['fulfilled', 'debited', 'confirmed', 'reversing', 'needs_review'], true)) {
            throw new BenefitFailure('REVERSAL_NOT_VERIFIED');
        }
        $tx = Transaction::findOrFail($r->transaction_id);
        if (! DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', 'reversed')->exists()) {
            if (! app(RecordedMedicalPaymentAuthority::class)->confirmed($tx, (int) $r->customer_id, (int) $r->cash_cents)) {
                throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
            }
            $gateway = (int) $r->cash_cents > 0 ? $this->gateways->get($r->provider) : null;
            if (DB::table('medical_purchase_intents')->where('id', $r->id)->where('state', $r->state)->update(['state' => 'reversing', 'reverse_started_at' => now(), 'updated_at' => now()]) !== 1) {
                return $this->result($this->row($actor, $ref));
            }
            try {
                $o = $gateway ? $gateway->reverse(Customer::findOrFail($r->customer_id), $tx, $ref, (int) $r->cash_cents) : new PaymentObservation('reversed', $ref, 0, 'internal-benefit-reversal-'.$r->id);
            } catch (\Throwable) {
                $this->uncertain($r);
                throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
            }
            if ($o->outcome !== 'reversed') {
                $this->uncertain($r);

                return $this->result($this->row($actor, $ref));
            }$this->record($r, $o);
        }
        $reservation = DB::table('medical_credit_reservations')->find($r->reservation_id);
        $this->reservations->compensate($actor, Crypt::decryptString($reservation->reference_ciphertext));
        DB::transaction(function () use ($r) {
            $current = DB::table('medical_purchase_intents')->where('id', $r->id)->lockForUpdate()->sole();
            if ($current->state === 'reversed') {
                return;
            }
            if ($current->membership_id) {
                $m = MedicalAttentionSubscription::findOrFail($current->membership_id);
                $m->delete(); // Soft-delete retains purchase/ledger history and excludes the refunded entitlement immediately.
                $customer = Customer::whereKey($current->customer_id)->lockForUpdate()->firstOrFail();
                $customer->update(['medical_attention_subscription_expires_at' => $customer->medicalAttentionSubscriptions()->active()->max('end_date')]);
            }
            DB::table('medical_purchase_intents')->where('id', $r->id)->update(['state' => 'reversed', 'updated_at' => now()]);
            DB::table('medical_settlement_outbox')->insertOrIgnore(['intent_id' => $r->id, 'kind' => 'reverse', 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        });

        return $this->result($this->row($actor, $ref));
    }

    public function cancelUncharged(BenefitActor $actor, string $ref): array
    {
        $r = $this->row($actor, $ref);
        DB::transaction(function () use ($r) {
            $current = DB::table('medical_purchase_intents')->where('id', $r->id)->lockForUpdate()->sole();
            if ($current->state === 'cancelled') {
                return;
            }
            if ($current->state !== 'reserved') {
                throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
            }
            if (DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', '<>', 'unpaid')->exists()) {
                throw new BenefitFailure('PAYMENT_OUTCOME_UNCERTAIN');
            }
            DB::table('medical_payment_verifications')->insert(['intent_id' => $r->id, 'kind' => 'unpaid', 'provider' => $r->provider, 'event_hash' => hash('sha256', 'never-dispatched|'.$r->reference), 'cash_cents' => $r->cash_cents, 'currency' => 'MXN', 'created_at' => now()]);
            Transaction::whereKey($r->transaction_id)->update(['payment_status' => 'cancelled']);
            DB::table('medical_purchase_intents')->where('id', $r->id)->update(['state' => 'cancelled', 'updated_at' => now()]);
        });
        $reservation = DB::table('medical_credit_reservations')->find($r->reservation_id);
        $this->reservations->release($actor, Crypt::decryptString($reservation->reference_ciphertext));

        return $this->result($this->row($actor, $ref));
    }

    public function bindCapturedPayPalOrderForOperator(\App\Models\User $operator, string $ref, string $order, string $approval): void
    {
        \Illuminate\Support\Facades\Gate::forUser($operator)->authorize('reconcileMedicalBenefits');
        if (! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $order) || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $approval)) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        $r = DB::table('medical_purchase_intents')->where('reference', $ref)->where('provider', 'paypal')->sole();
        $ctx = DB::table('medical_credit_reservations as r')->join('opaque_benefit_contexts as c', 'c.id', '=', 'r.context_id')->where('r.id', $r->reservation_id)->first(['c.user_id', 'c.consumer_id', 'c.channel_id', 'c.delegation_id']);
        $actor = new BenefitActor((int) $ctx->user_id, (string) $ctx->consumer_id, (string) $ctx->delegation_id, (string) $ctx->channel_id);
        $this->gate->authorize($actor, $operator);
        if (! in_array($r->state, ['charging', 'capture_requested', 'awaiting_payment', 'needs_review'], true)) {
            throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
        }
        $tx = Transaction::findOrFail($r->transaction_id);
        if ($tx->provider_order_id && $tx->provider_order_id !== $order) {
            throw new BenefitFailure('PROVIDER_ORDER_CONFLICT');
        }
        $probe = clone $tx;
        $probe->provider_order_id = $order;
        $observation = $this->gateways->get('paypal')->observe(Customer::findOrFail($r->customer_id), $probe, $ref, (int) $r->cash_cents);
        if ($observation->outcome !== 'confirmed') {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        DB::transaction(function () use ($r, $operator, $order, $approval) {
            $current = DB::table('medical_purchase_intents')->where('id', $r->id)->lockForUpdate()->sole();
            if (! in_array($current->state, ['charging', 'capture_requested', 'awaiting_payment', 'needs_review'], true)) {
                throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
            }
            $tx = Transaction::whereKey($r->transaction_id)->lockForUpdate()->firstOrFail();
            if ($tx->provider_order_id && $tx->provider_order_id !== $order) {
                throw new BenefitFailure('PROVIDER_ORDER_CONFLICT');
            }
            $tx->update(['provider_order_id' => $order]);
            DB::table('medical_recovery_audit')->insertOrIgnore(['operator_id' => $operator->id, 'intent_id' => $r->id, 'action' => 'bind_order', 'approval_ref' => $approval, 'result_state' => 'observed', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** Trusted administrative recovery only. A clone confines the revoked-client exception to refund/cancel, never begin/start/fulfillment. */
    public function recoverForOperator(\App\Models\User $operator, string $ref, string $action, string $approval): array
    {
        \Illuminate\Support\Facades\Gate::forUser($operator)->authorize('reconcileMedicalBenefits');
        if (! in_array($action, ['refund', 'cancel_uncalled'], true) || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $approval)) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        $r = DB::table('medical_purchase_intents')->where('reference', $ref)->sole();
        $ctx = DB::table('medical_credit_reservations as r')->join('opaque_benefit_contexts as c', 'c.id', '=', 'r.context_id')->where('r.id', $r->reservation_id)->first(['c.user_id', 'c.consumer_id', 'c.channel_id', 'c.delegation_id']);
        $actor = new BenefitActor((int) $ctx->user_id, (string) $ctx->consumer_id, (string) $ctx->delegation_id, (string) $ctx->channel_id);
        $recovery = clone $this;
        $recovery->recoveryOperator = $operator;
        $recovery->row($actor, $ref);
        DB::table('medical_recovery_audit')->insertOrIgnore(['operator_id' => $operator->id, 'intent_id' => $r->id, 'action' => $action, 'approval_ref' => $approval, 'result_state' => 'started', 'created_at' => now(), 'updated_at' => now()]);
        if ($action === 'cancel_uncalled') {
            $result = $recovery->cancelUncharged($actor, $ref);
        } else {
            \Illuminate\Support\Facades\Gate::forUser($operator)->authorize('reverseMedicalBenefits', [$actor, $ref]);
            if (! DB::table('medical_payment_verifications')->where('intent_id', $r->id)->where('kind', 'confirmed')->exists() && (int) $r->cash_cents > 0) {
                try {
                    $observation = $this->gateways->get($r->provider)->observe(Customer::findOrFail($r->customer_id), Transaction::findOrFail($r->transaction_id), $ref, (int) $r->cash_cents);
                } catch (\Throwable) {
                    $observation = new PaymentObservation('unknown', $ref, (int) $r->cash_cents);
                }
                if (in_array($observation->outcome, ['confirmed', 'reversed'], true)) {
                    $recovery->record($r, $observation);
                } else {
                    $recovery->uncertain($r);

                    return $recovery->result($recovery->row($actor, $ref));
                }
            }
            $fresh = $recovery->row($actor, $ref);
            $result = $fresh->reverse_started_at ? $recovery->reconcile($actor, $ref) : $recovery->reverse($actor, $ref);
        }
        DB::table('medical_recovery_audit')->where('intent_id', $r->id)->where('action', $action)->where('approval_ref', $approval)->update(['result_state' => $result['state'], 'updated_at' => now()]);

        return $result;
    }
}

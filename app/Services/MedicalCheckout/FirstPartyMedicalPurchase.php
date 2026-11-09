<?php

namespace App\Services\MedicalCheckout;

use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Models\Transaction;
use App\Services\Api\V1\Benefits\EloquentBenefitStateStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** First-party payment authorization, distinct from selection/benefit consent. Never accepts an actor from a client. */
final class FirstPartyMedicalPurchase
{
    public const TERMS_VERSION = 'medical-purchase-first-party-01';

    public const TERMS = 'Autorizo personalmente esta compra de membresía médica anual en FAMEDIC por el importe final mostrado en MXN, con el cupón o saldo indicado. Esta autorización es distinta de aplicar el beneficio y no concede a Leo ni ATIX permiso para cobrar. Si el resultado es incierto, consultaré el estado sin repetir la compra. Un reverso requiere verificación y autorización independientes.';

    public function __construct(private MedicalFundingGate $gate, private MedicalPurchaseSaga $saga) {}

    private function user(Request $r): \App\Models\User
    {
        if (config('akubica_connectors.first_party_purchase.enabled') !== true || config('akubica_connectors.first_party_purchase.certified') !== true) {
            throw new BenefitFailure('FINANCIAL_WRITES_PENDING_APPROVAL');
        }
        // A user PAT or machine credential is never a first-party payment credential, even with a session cookie.
        foreach (['Authorization', 'X-Consumer-Credential', 'X-Api-Key'] as $header) {
            if ($r->headers->has($header)) {
                throw new BenefitFailure('CONSUMER_NOT_AUTHORIZED');
            }
        }
        $guard = Auth::guard('web');
        $u = $guard->user();
        if (! $u || ! $r->hasSession() || (string) $r->session()->get($guard->getName()) !== (string) $u->id) {
            throw new BenefitFailure('UNAUTHENTICATED');
        }
        $at = (int) $r->session()->get('auth.password_confirmed_at', 0);
        if ($at < now()->subMinutes(5)->timestamp || $at > now()->timestamp) {
            throw new BenefitFailure('FIRST_PARTY_CONFIRMATION_REQUIRED');
        }

        return $u;
    }

    private function actor(object $ctx): BenefitActor
    {
        $a = new BenefitActor((int) $ctx->user_id, (string) $ctx->consumer_id, (string) $ctx->delegation_id, (string) $ctx->channel_id);
        $this->gate->authorize($a);

        return $a;
    }

    private function sessionHash(Request $r): string
    {
        if (! $r->session()->has('medical_purchase_browser_nonce')) {
            $r->session()->put('medical_purchase_browser_nonce', bin2hex(random_bytes(32)));
        }

        return hash('sha256', $r->session()->get('medical_purchase_browser_nonce'));
    }

    private function snapshot(EloquentBenefitStateStore $store, array $in): array
    {
        $cart = $store->get('carts', OpaqueReference::key($in['cart_ref'], 'crt'));
        $app = $store->get('applications', OpaqueReference::key($in['application_ref'], 'app'));
        if (! $cart || ! $app || ! $cart['active'] || $cart['application'] !== $in['application_ref'] || $app['status'] !== 'applied' || $app['post_revision'] !== $cart['revision']) {
            throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
        }
        $source = $store->get('sources', (string) $app['source_id']);
        if (! $source || ! $source['active'] || $app['source_stamp'] !== hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))) {
            throw new BenefitFailure('CREDIT_NOT_ELIGIBLE');
        }
        $subtotal = (int) $cart['subtotal_cents'];
        if ($subtotal !== (int) config('famedic.medical_attention_subscription_price_cents') || (int) $app['amount'] <= 0 || (int) $app['amount'] > $subtotal) {
            throw new BenefitFailure('PURCHASE_AMOUNT_CONFLICT');
        }

        return ['cart_version' => $cart['version'], 'subtotal_cents' => $subtotal, 'credit_cents' => $app['kind'] === 'credit' ? (int) $app['amount'] : 0, 'discount_cents' => $app['kind'] === 'coupon' ? (int) $app['amount'] : 0, 'benefit_type' => $app['kind'], 'cash_cents' => $subtotal - (int) $app['amount'], 'currency' => 'MXN'];
    }

    public function review(Request $r): array
    {
        $u = $this->user($r);
        $in = $r->query->all();
        if (array_diff(array_keys($in), ['cart_ref', 'application_ref', 'provider', 'payment_method']) || ! isset($in['cart_ref'], $in['application_ref']) || count(array_filter($in, 'is_string')) !== count($in)) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        $in += ['provider' => 'paypal', 'payment_method' => ''];
        // ODESSA mixed payments stay unavailable until R29 has authoritative query/reversal support.
        if (! in_array($in['provider'], ['paypal', 'efevoopay'], true)) {
            throw new BenefitFailure('GATEWAY_NOT_SUPPORTED');
        }
        $ctx = DB::table('opaque_benefit_contexts')->where('user_id', $u->id)->where('cart_ref_hash', OpaqueReference::key($in['cart_ref'], 'crt'))->first();
        if (! $ctx) {
            throw new BenefitFailure('CART_REFERENCE_NOT_FOUND');
        }
        $actor = $this->actor($ctx);
        $store = new EloquentBenefitStateStore($actor, (int) $ctx->id);

        return $store->transaction(function () use ($store, $in, $r, $u, $ctx) {
            $hash = OpaqueReference::key($in['application_ref'], 'app');
            $prior = DB::table('first_party_medical_checkouts')->where('context_id', $ctx->id)->where('application_hash', $hash)->lockForUpdate()->first();
            if ($prior && ($prior->accepted_at || now()->lt($prior->expires_at))) {
                if (! hash_equals($prior->session_hash, $this->sessionHash($r))) {
                    throw new BenefitFailure('FIRST_PARTY_CONFIRMATION_REQUIRED');
                }
                $payload = $this->decode($prior);
                if ($payload['provider'] !== $in['provider'] || $payload['payment_method'] !== $in['payment_method']) {
                    throw new BenefitFailure('IDEMPOTENCY_KEY_CONFLICT');
                }

                return $this->view($prior, $payload);
            }
            $snap = $this->snapshot($store, $in);
            if ($snap['cash_cents'] > 0) {
                app(MedicalGatewayRegistry::class)->get($in['provider']);
                if ($in['provider'] === 'efevoopay' && (! $u->customer->efevooTokens()->active()->whereKey($in['payment_method'])->first() || config('efevoopay.verify_ssl') !== true)) {
                    throw new BenefitFailure('GATEWAY_CERTIFICATION_PENDING');
                }
            }
            $ref = OpaqueReference::issue('pur');
            $payload = $in + $snap + ['checkout_ref' => $ref, 'terms_version' => self::TERMS_VERSION, 'terms_digest' => hash('sha256', self::TERMS)];
            $values = ['user_id' => $u->id, 'session_hash' => $this->sessionHash($r), 'reference_hash' => OpaqueReference::key($ref, 'pur'), 'payload_ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'expires_at' => now()->addSeconds(120), 'updated_at' => now()];
            if ($prior) {
                DB::table('first_party_medical_checkouts')->where('id', $prior->id)->update($values);
                $id = $prior->id;
            } else {
                $id = DB::table('first_party_medical_checkouts')->insertGetId($values + ['context_id' => $ctx->id, 'application_hash' => $hash, 'created_at' => now()]);
            }

            return $this->view(DB::table('first_party_medical_checkouts')->find($id), $payload);
        });
    }

    private function decode(object $row): array
    {
        return json_decode(Crypt::decryptString($row->payload_ciphertext), true, 512, JSON_THROW_ON_ERROR);
    }

    private function view(object $row, array $p): array
    {
        return array_intersect_key($p, array_flip(['checkout_ref', 'cart_ref', 'application_ref', 'cart_version', 'provider', 'subtotal_cents', 'credit_cents', 'discount_cents', 'benefit_type', 'cash_cents', 'currency', 'terms_version'])) + ['expires_at' => $row->expires_at, 'accepted' => $row->accepted_at !== null, 'purchase_ref' => $row->purchase_ref, 'terms' => self::TERMS];
    }

    private function receipt(Request $r, string $ref): array
    {
        $u = $this->user($r);
        $row = DB::table('first_party_medical_checkouts')->where('reference_hash', OpaqueReference::key($ref, 'pur'))->where('user_id', $u->id)->first();
        if (! $row) {
            throw new BenefitFailure('PURCHASE_NOT_FOUND');
        }
        if (! hash_equals($row->session_hash, $this->sessionHash($r))) {
            throw new BenefitFailure('FIRST_PARTY_CONFIRMATION_REQUIRED');
        }
        $ctx = DB::table('opaque_benefit_contexts')->find($row->context_id);

        $actor = $this->actor($ctx);
        // A DB failure after reservation/intent creation may precede the receipt pointer update.
        // Resolve only the durable server-generated key; never accept a purchase reference from the request.
        if (! $row->purchase_ref && $row->accepted_at) {
            $intent = DB::table('medical_purchase_intents')->where('actor_hash', $actor->namespace())->where('key_hash', hash('sha256', $actor->namespace().'|firstparty_'.$row->reference_hash))->first();
            if ($intent) {
                $row->purchase_ref = $intent->reference;
            }
        }

        return [$row, $this->decode($row), $actor];
    }

    public function confirm(Request $r): array
    {
        $in = $r->request->all();
        unset($in['_token']);
        if (array_diff(array_keys($in), ['checkout_ref', 'authorize_payment']) || ($in['authorize_payment'] ?? null) !== 'yes' || ! is_string($in['checkout_ref'] ?? null)) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        [$row,$p,$actor] = $this->receipt($r, $in['checkout_ref']);
        if ($p['terms_version'] !== self::TERMS_VERSION || ! hash_equals($p['terms_digest'], hash('sha256', self::TERMS))) {
            throw new BenefitFailure('CONFIRMATION_CONTEXT_CHANGED');
        }
        if (! $row->purchase_ref) {
            if ($p['cash_cents'] > 0) {
                app(MedicalGatewayRegistry::class)->get($p['provider']);
                if ($p['provider'] === 'efevoopay' && (! Auth::guard('web')->user()->customer->efevooTokens()->active()->whereKey($p['payment_method'])->exists() || config('efevoopay.verify_ssl') !== true)) {
                    throw new BenefitFailure('GATEWAY_CERTIFICATION_PENDING');
                }
            }
            if (now()->gte($row->expires_at)) {
                throw new BenefitFailure('CONFIRMATION_EXPIRED');
            }
            $store = new EloquentBenefitStateStore($actor, (int) $row->context_id);
            $store->transaction(function () use ($store, $p) {
                if ($this->snapshot($store, $p) !== array_intersect_key($p, array_flip(['cart_version', 'subtotal_cents', 'credit_cents', 'discount_cents', 'benefit_type', 'cash_cents', 'currency']))) {
                    throw new BenefitFailure('PURCHASE_CONTEXT_CHANGED');
                }
            });
            // Persist the distinct user authorization before any reservation or provider effect. Stable key makes crash/retry safe.
            DB::table('first_party_medical_checkouts')->where('id', $row->id)->whereNull('accepted_at')->update(['accepted_at' => now(), 'updated_at' => now()]);
            $begun = app(\App\Actions\MedicalAttention\PurchaseRegularSubscriptionAction::class)->beginBenefitPurchase($actor, $p['cart_ref'], $p['application_ref'], 'firstparty_'.$row->reference_hash, $p['provider']);
            if (array_diff_assoc(array_intersect_key($begun, array_flip(['subtotal_cents', 'credit_cents', 'discount_cents', 'cash_cents', 'currency'])), $p)) {
                throw new BenefitFailure('PURCHASE_AMOUNT_CONFLICT');
            }
            DB::table('first_party_medical_checkouts')->where('id', $row->id)->update(['purchase_ref' => $begun['purchase_ref'], 'updated_at' => now()]);
            $row->purchase_ref = $begun['purchase_ref'];
        }
        if (now()->gte($row->expires_at) && $this->saga->status($actor, $row->purchase_ref)['state'] === 'reserved') {
            throw new BenefitFailure('CONFIRMATION_EXPIRED');
        }

        // Start's CAS dispatches at most once; an already-dispatched retry never calls the provider again.
        return $this->saga->start($actor, $row->purchase_ref, $p['payment_method']);
    }

    public function status(Request $r, string $ref): array
    {
        [$row,$p,$actor] = $this->receipt($r, $ref);
        if (! $row->purchase_ref) {
            return ['state' => $row->accepted_at ? 'authorization_recorded' : 'awaiting_confirmation'];
        }

        return $this->saga->status($actor, $row->purchase_ref);
    }

    public function action(Request $r, string $ref, string $action): array
    {
        $in = $r->request->all();
        unset($in['_token']);
        if ($in !== ['authorize_payment' => 'yes']) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        [$row,$p,$actor] = $this->receipt($r, $ref);
        if (! $row->accepted_at || ! $row->purchase_ref) {
            throw new BenefitFailure('CONFIRMATION_REQUIRED');
        }

        return match ($action) {
            'recover' => $this->saga->reconcile($actor, $row->purchase_ref),
            'capture' => $p['provider'] === 'paypal' ? $this->saga->capturePayPal(Transaction::where('reference_id', $row->purchase_ref)->firstOrFail(), Auth::guard('web')->user()->customer) : throw new BenefitFailure('GATEWAY_NOT_SUPPORTED'),
            'reverse' => $this->saga->reverse($actor, $row->purchase_ref),
            'cancel' => $this->saga->cancelUncharged($actor, $row->purchase_ref),
            default => throw new BenefitFailure('VALIDATION_ERROR'),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Services\Api\V1\Auth\ConsumerRegistryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class FirstPartyBenefitConsent
{
    public function __construct(private BenefitApprovals $approvals) {}

    private function scope(Request $request): array
    {
        if (! $this->approvals->consent() || ! $this->approvals->textVersion()) {
            throw new BenefitFailure('CONSENT_ISSUANCE_PENDING_APPROVAL');
        }
        $user = Auth::guard('web')->user();
        if (! $user) {
            throw new BenefitFailure('UNAUTHENTICATED');
        }
        if (! $request->hasSession() || (int) $request->session()->get('auth.password_confirmed_at', 0) < now()->subMinutes(5)->timestamp) {
            throw new BenefitFailure('FIRST_PARTY_CONFIRMATION_REQUIRED');
        }
        $body = $request->json()->all();
        $allowed = ['cart_ref', 'cart_version', 'target_ref', 'operation', 'text_version'];
        if (array_diff(array_keys($body), $allowed) || count($body) !== count($allowed) || count(array_filter($body, 'is_string')) !== count($body)) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        if (! in_array($body['operation'], ['apply', 'remove'], true) || $body['text_version'] !== $this->approvals->textVersion()) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        $ctx = DB::table('opaque_benefit_contexts')->where('cart_ref_hash', OpaqueReference::key($body['cart_ref'], 'crt'))->where('user_id', $user->id)->first();
        if (! $ctx) {
            throw new BenefitFailure('CART_REFERENCE_NOT_FOUND');
        }
        $consumer = ApiConsumer::find($ctx->consumer_id);
        $channel = ApiConsumerChannel::find($ctx->channel_id);
        $delegation = ApiConsumerDelegation::find($ctx->delegation_id);
        if (! $consumer || ! $channel || ! $delegation || ! ConsumerRegistryState::active($consumer) || ! ConsumerRegistryState::active($channel) || ! ConsumerRegistryState::active($delegation)
            || (int) $delegation->user_id !== (int) $user->id || (int) $delegation->customer_id !== (int) $user->customer?->id
            || (int) $channel->consumer_id !== (int) $consumer->id || (int) $delegation->consumer_id !== (int) $consumer->id || (int) $delegation->channel_id !== (int) $channel->id
            || ! in_array('benefits:'.$body['operation'], ConsumerRegistryState::capabilities($consumer->capabilities), true)
            || ! in_array('benefits:'.$body['operation'], ConsumerRegistryState::capabilities($delegation->capabilities), true)) {
            throw new BenefitFailure('CONSUMER_NOT_AUTHORIZED');
        }
        $actor = new BenefitActor((int) $user->id, (string) $ctx->consumer_id, (string) $ctx->delegation_id, (string) $ctx->channel_id);

        return [$actor, $ctx, $body];
    }

    private function intent(EloquentBenefitStateStore $store, array $body): array
    {
        $cart = $store->get('carts', OpaqueReference::key($body['cart_ref'], 'crt'));
        if (! $cart) {
            throw new BenefitFailure('CART_REFERENCE_NOT_FOUND');
        }
        if ($body['cart_version'] !== $cart['version']) {
            throw new BenefitFailure('CART_VERSION_CONFLICT');
        }
        $apply = $body['operation'] === 'apply';
        $record = $store->get($apply ? 'options' : 'applications', OpaqueReference::key($body['target_ref'], $apply ? 'bnf' : 'app'));
        if (! $record) {
            throw new BenefitFailure($apply ? 'BENEFIT_REFERENCE_NOT_FOUND' : 'BENEFIT_APPLICATION_NOT_FOUND');
        }
        if ($apply && ($record['used'] || $record['expires_at'] <= now()->timestamp)) {
            throw new BenefitFailure('BENEFIT_REFERENCE_EXPIRED');
        }
        $amount = $apply ? $record['amount'] : 0;
        $source = $store->get('sources', $record['source_id']);
        if ($apply && (! $source || ! $source['active'])) {
            throw new BenefitFailure('BENEFIT_NOT_ELIGIBLE');
        }
        if ($record['actor'] !== $store->actor->namespace() || $record['cart_ref'] !== $body['cart_ref']) {
            throw new BenefitFailure('BENEFIT_REFERENCE_NOT_FOUND');
        }
        if ($apply && ($record['cart_version'] !== $cart['version'] || $record['stamp'] !== \App\Domain\Akubica\Benefits\OpaqueBenefitWorkflow::sourceContextStamp($cart, $source))) {
            throw new BenefitFailure('CONFIRMATION_CONTEXT_CHANGED');
        }
        if (! $apply && ($record['status'] !== 'applied' || $cart['application'] !== $body['target_ref'])) {
            throw new BenefitFailure('BENEFIT_APPLICATION_NOT_ACTIVE');
        }
        $kind = $source['kind'] ?? $record['kind'];
        $total = ['subtotal_cents' => $cart['subtotal_cents'], 'discount_cents' => $apply && $kind === 'coupon' ? $amount : 0, 'credit_applied_cents' => $apply && $kind === 'credit' ? $amount : 0, 'total_cents' => $cart['subtotal_cents'] - $amount, 'currency' => 'MXN'];

        $text = app(ConsentTextRegistry::class)->active($body['text_version']);
        if ($this->approvals instanceof CertifiedBenefitApprovals && ! $text) {
            throw new BenefitFailure('CONSENT_ISSUANCE_PENDING_APPROVAL');
        }

        return ['actor' => $store->actor->namespace(), 'cart_ref' => $body['cart_ref'], 'cart_version' => $cart['version'], 'operation' => $body['operation'], 'target' => $body['target_ref'], 'amount' => $amount, 'benefit_type' => $kind, 'totals' => $total, 'text_version' => $body['text_version'], 'text_digest' => $text?->digest, 'expires_at' => min(now()->timestamp + 120, $record['expires_at'] ?? PHP_INT_MAX), 'used' => false, 'revoked' => false];
    }

    public function review(Request $request): array
    {
        [$actor,$ctx,$body] = $this->scope($request);
        $store = new EloquentBenefitStateStore($actor, (int) $ctx->id);

        return $store->transaction(function () use ($store, $body) {
            $intent = $this->intent($store, $body);

            return ['operation' => $intent['operation'], 'cart_ref' => $body['cart_ref'], 'cart_version' => $body['cart_version'], 'target_ref' => $body['target_ref'], 'amount_cents' => $intent['amount'], 'benefit_type' => $intent['benefit_type'], 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $intent['expires_at']), 'totals' => $intent['totals'], 'text_version' => $intent['text_version']];
        });
    }

    public function issue(Request $request): array
    {
        [$actor,$ctx,$body] = $this->scope($request);
        $store = new EloquentBenefitStateStore($actor, (int) $ctx->id);

        return $store->transaction(function () use ($store, $body) {
            $intent = $this->intent($store, $body);
            $ref = OpaqueReference::issue('cnf');
            $store->put('confirmations', OpaqueReference::key($ref, 'cnf'), $intent);

            return ['confirmation_ref' => $ref, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $intent['expires_at'])];
        });
    }

    public function revoke(Request $request, string $ref): array
    {
        if (! $this->approvals->consent()) {
            throw new BenefitFailure('CONSENT_ISSUANCE_PENDING_APPROVAL');
        }$user = Auth::guard('web')->user();
        if (! $user) {
            throw new BenefitFailure('UNAUTHENTICATED');
        }
        if (! $request->hasSession() || (int) $request->session()->get('auth.password_confirmed_at', 0) < now()->subMinutes(5)->timestamp) {
            throw new BenefitFailure('FIRST_PARTY_CONFIRMATION_REQUIRED');
        }
        $row = DB::table('opaque_benefit_states')->join('opaque_benefit_contexts', 'opaque_benefit_states.context_id', '=', 'opaque_benefit_contexts.id')->where('bucket', 'confirmations')->where('key_hash', OpaqueReference::key($ref, 'cnf'))->where('opaque_benefit_contexts.user_id', $user->id)->first(['opaque_benefit_contexts.*']);
        if (! $row) {
            throw new BenefitFailure('CONFIRMATION_REFERENCE_NOT_FOUND');
        }
        $actor = new BenefitActor((int) $user->id, (string) $row->consumer_id, (string) $row->delegation_id, (string) $row->channel_id);
        $store = new EloquentBenefitStateStore($actor, (int) $row->id);

        return $store->transaction(function () use ($store, $ref) {
            $key = OpaqueReference::key($ref, 'cnf');
            $consent = $store->get('confirmations', $key);
            if (! $consent) {
                throw new BenefitFailure('CONFIRMATION_REFERENCE_NOT_FOUND');
            }
            if ($consent['used']) {
                throw new BenefitFailure('CONFIRMATION_ALREADY_USED');
            }$consent['revoked'] = true;
            $store->put('confirmations', $key, $consent);

            return ['revoked' => true];
        });
    }
}

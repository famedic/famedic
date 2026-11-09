<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

/** Benefit rules shared by isolated fixtures and the gated Laravel adapter. */
final class OpaqueBenefitWorkflow
{
    public function __construct(private BenefitStateStore $store, private BenefitsAuthorizer $auth) {}

    private function fail(string $code): never
    {
        throw new BenefitFailure($code);
    }

    private function cart(BenefitActor $actor, string $ref): array
    {
        $cart = $this->store->get('carts', OpaqueReference::key($ref, 'crt'));
        if (! $cart || $cart['user_id'] !== $actor->userId || $cart['consumer_id'] !== $actor->consumerId
            || $cart['delegation_id'] !== $actor->delegationId) {
            $this->fail('CART_REFERENCE_NOT_FOUND');
        }
        if ($cart['service_line'] !== 'medical_services') {
            $this->fail('CART_SERVICE_LINE_MISMATCH');
        }
        if (! $cart['active'] || $cart['subtotal_cents'] <= 0) {
            $this->fail('EMPTY_CART');
        }

        return $cart;
    }

    private function sourceEligible(array $source, array $cart, BenefitActor $actor, int $now): bool
    {
        return $source['user_id'] === $actor->userId && $source['active'] && $source['approved']
            && in_array($source['kind'], ['coupon', 'credit'], true) && $source['service_line'] === 'medical_services'
            && $source['starts_at'] <= $now && $source['expires_at'] >= $now && $source['remaining_cents'] > 0
            && $cart['subtotal_cents'] >= $source['minimum_cents'];
    }

    public static function sourceContextStamp(array $cart, array $source): string
    {
        return hash('sha256', json_encode([$cart['revision'], $cart['subtotal_cents'], $cart['version'], $source], JSON_THROW_ON_ERROR));
    }

    private function totals(array $cart, ?string $kind = null, int $amount = 0): array
    {
        $discount = $kind === 'coupon' ? $amount : 0;
        $credit = $kind === 'credit' ? $amount : 0;

        return ['subtotal_cents' => $cart['subtotal_cents'], 'discount_cents' => $discount, 'credit_applied_cents' => $credit,
            'total_cents' => $cart['subtotal_cents'] - $discount - $credit, 'currency' => 'MXN'];
    }

    /** Internal cartRef must already be resolved by an approved ownership adapter.
     * Prototype deliberately has no cursor/pagination or active catalog route.
     */
    public function preview(BenefitActor $actor, string $cartRef, int $now): array
    {
        $this->auth->authorize($actor, 'benefits:read', $now);

        return $this->store->transaction(function () use ($actor, $cartRef, $now) {
            $cart = $this->cart($actor, $cartRef);
            $options = [];
            $selected = isset($cart['application']) ? $this->store->get('applications', OpaqueReference::key($cart['application'], 'app')) : null;
            foreach ($this->store->all('sources') as $id => $source) {
                if ($selected && $selected['source_id'] === (string) $id) {
                    continue;
                }
                if (! $this->sourceEligible($source, $cart, $actor, $now)) {
                    continue;
                }
                $ref = OpaqueReference::issue('bnf');
                $amount = PartialCredit::preview($source['remaining_cents'], $cart['subtotal_cents']);
                $this->store->put('options', OpaqueReference::key($ref, 'bnf'), [
                    'actor' => $actor->namespace(), 'cart_ref' => $cartRef, 'cart_version' => $cart['version'], 'source_id' => (string) $id,
                    'stamp' => self::sourceContextStamp($cart, $source), 'amount' => $amount, 'expires_at' => $now + 300, 'used' => false]);
                $options[] = ['benefit_ref' => $ref, 'benefit_type' => $source['kind'], 'estimated_amount_cents' => $amount,
                    'max_applicable_cents' => $amount, 'reference_expires_at' => $now + 300];
            }
            $application = $cart['application'] ?? null;
            $amount = 0;
            $kind = null;
            $status = null;
            if ($application) {
                $record = $this->store->get('applications', OpaqueReference::key($application, 'app'));
                $source = $record ? $this->store->get('sources', $record['source_id']) : null;
                $valid = $source && $this->sourceEligible($source, $cart, $actor, $now)
                    && $record['post_revision'] === $cart['revision'] && $record['source_stamp'] === hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
                $status = $valid ? 'applied' : 'invalidated';
                $amount = $valid ? $record['amount'] : 0;
                $kind = $record['kind'] ?? null;
            }

            return ['cart_ref' => $cartRef, 'cart_version' => $cart['version'], 'service_line' => 'medical_services', 'benefits' => $options,
                'application' => $application ? ['application_ref' => $application, 'status' => $status, 'applied_cents' => $amount] : null,
                'totals' => $this->totals($cart, $kind, $amount), 'availability' => 'preview_only'];
        });
    }

    public function apply(BenefitActor $actor, array $body, string $idempotencyKey, int $now): array
    {
        return $this->mutate($actor, 'apply', $body, $idempotencyKey, $now);
    }

    public function remove(BenefitActor $actor, array $body, string $idempotencyKey, int $now): array
    {
        return $this->mutate($actor, 'remove', $body, $idempotencyKey, $now);
    }

    private function mutate(BenefitActor $actor, string $operation, array $body, string $key, int $now): array
    {
        $this->auth->authorize($actor, 'benefits:'.$operation, $now);
        if (! preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $key)) {
            $this->fail('IDEMPOTENCY_KEY_REQUIRED');
        }
        $required = ['service_line', 'cart_ref', 'cart_version', 'confirmation_ref', $operation === 'apply' ? 'benefit_ref' : 'application_ref'];
        $keys = array_keys($body);
        sort($keys);
        sort($required);
        if ($keys !== $required || count(array_filter($body, 'is_string')) !== count($body)) {
            $this->fail('VALIDATION_ERROR');
        }
        if ($body['service_line'] !== 'medical_services') {
            $this->fail('CART_SERVICE_LINE_MISMATCH');
        }
        foreach (['cart_ref' => 'crt', 'cart_version' => 'ver', 'confirmation_ref' => 'cnf', $operation === 'apply' ? 'benefit_ref' : 'application_ref' => $operation === 'apply' ? 'bnf' : 'app'] as $field => $type) {
            OpaqueReference::key($body[$field], $type);
        }
        ksort($body);
        $fingerprint = hash('sha256', json_encode([$operation, $body], JSON_THROW_ON_ERROR));
        $receiptKey = hash('sha256', json_encode([$actor->namespace(), $operation, $key], JSON_THROW_ON_ERROR));

        return $this->store->transaction(function () use ($actor, $operation, $body, $now, $fingerprint, $receiptKey) {
            $prior = $this->store->get('receipts', $receiptKey);
            if ($prior) {
                if ($prior['fingerprint'] !== $fingerprint) {
                    $this->fail('IDEMPOTENCY_KEY_CONFLICT');
                }
                // Tombstones are retained; never silently repeat a committed effect after expiry.
                if ($now >= $prior['expires_at']) {
                    $this->fail('IDEMPOTENCY_RECEIPT_EXPIRED');
                }

                return $prior['result'] + ['replayed' => true];
            }
            $cart = $this->cart($actor, $body['cart_ref']);
            $ref = $body[$operation === 'apply' ? 'benefit_ref' : 'application_ref'];
            $table = $operation === 'apply' ? 'options' : 'applications';
            $type = $operation === 'apply' ? 'bnf' : 'app';
            $record = $this->store->get($table, OpaqueReference::key($ref, $type));
            if (! $record || $record['actor'] !== $actor->namespace() || $record['cart_ref'] !== $body['cart_ref']) {
                $this->fail($operation === 'apply' ? 'BENEFIT_REFERENCE_NOT_FOUND' : 'BENEFIT_APPLICATION_NOT_FOUND');
            }
            $confirmKey = OpaqueReference::key($body['confirmation_ref'], 'cnf');
            $consent = $this->store->get('confirmations', $confirmKey);
            if (! $consent || $consent['actor'] !== $actor->namespace() || $consent['cart_ref'] !== $body['cart_ref']) {
                $this->fail('CONFIRMATION_REFERENCE_NOT_FOUND');
            }
            if ($consent['revoked'] ?? false) { $this->fail('CONFIRMATION_REVOKED'); }
            if ($now >= $consent['expires_at']) {
                $this->fail('CONFIRMATION_EXPIRED');
            }
            if ($consent['used']) {
                $this->fail('CONFIRMATION_ALREADY_USED');
            }
            if ($consent['operation'] !== $operation || $consent['target'] !== $ref || $consent['cart_version'] !== $body['cart_version']) {
                $this->fail('CONFIRMATION_CONTEXT_CHANGED');
            }
            if ($cart['version'] !== $body['cart_version']) {
                $this->fail('CART_VERSION_CONFLICT');
            }
            if ($operation === 'apply') {
                if ($now >= $record['expires_at']) {
                    $this->fail('BENEFIT_REFERENCE_EXPIRED');
                }
                if ($record['used']) {
                    $this->fail('BENEFIT_REFERENCE_ALREADY_USED');
                }
                if ($cart['application'] !== null) {
                    $this->fail('BENEFIT_ALREADY_APPLIED');
                }
                $source = $this->store->get('sources', $record['source_id']);
                if (! $source || ! $this->sourceEligible($source, $cart, $actor, $now)) {
                    $this->fail('BENEFIT_NOT_ELIGIBLE');
                }
                if ($record['stamp'] !== self::sourceContextStamp($cart, $source)) {
                    $this->fail('CONFIRMATION_CONTEXT_CHANGED');
                }
                $amount = PartialCredit::preview($source['remaining_cents'], $cart['subtotal_cents']);
                $kind = $source['kind'];
                $applicationRef = OpaqueReference::issue('app');
            } else {
                if ($record['status'] !== 'applied' || $cart['application'] !== $ref) {
                    $this->fail('BENEFIT_APPLICATION_NOT_ACTIVE');
                }
                $amount = 0;
                $kind = null;
                $applicationRef = $ref;
            }
            $expectedTotals = $this->totals($cart, $kind, $amount);
            if ($consent['amount'] !== $amount || $consent['totals'] !== $expectedTotals || $consent['text_version'] === '') {
                $this->fail('CONFIRMATION_CONTEXT_CHANGED');
            }
            $consent['used'] = true;
            $this->store->put('confirmations', $confirmKey, $consent);
            $cart['revision']++;
            $cart['version'] = OpaqueReference::issue('ver');
            $cart['application'] = $operation === 'apply' ? $applicationRef : null;
            if ($operation === 'apply') {
                $record['used'] = true;
                $this->store->put('options', OpaqueReference::key($ref, 'bnf'), $record);
                $application = ['actor' => $actor->namespace(), 'cart_ref' => $body['cart_ref'], 'source_id' => $record['source_id'],
                    'kind' => $kind, 'amount' => $amount, 'status' => 'applied', 'post_revision' => $cart['revision'],
                    'source_stamp' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))];
            } else {
                $application = $record;
                $application['status'] = 'removed';
            }
            $this->store->put('applications', OpaqueReference::key($applicationRef, 'app'), $application);
            $this->store->put('carts', OpaqueReference::key($body['cart_ref'], 'crt'), $cart);
            $result = ['cart_ref' => $body['cart_ref'], 'cart_version' => $cart['version'], 'application_ref' => $applicationRef,
                'status' => $operation === 'apply' ? 'applied' : 'removed', 'totals' => $expectedTotals, 'availability' => 'preview_only'];
            $this->store->put('receipts', $receiptKey, ['fingerprint' => $fingerprint, 'result' => $result, 'expires_at' => $now + 86400]);

            return $result + ['replayed' => false];
        });
    }
}

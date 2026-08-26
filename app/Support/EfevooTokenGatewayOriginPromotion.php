<?php

namespace App\Support;

use App\Models\EfevooToken;
use Illuminate\Support\Facades\Log;

class EfevooTokenGatewayOriginPromotion
{
    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function promote(EfevooToken $token, string $targetOrigin, array $auditContext = []): EfevooToken
    {
        $targetOrigin = strtolower(trim($targetOrigin));

        if (! in_array($targetOrigin, EfevooTokenGatewayOriginPolicy::allowedOrigins(), true)) {
            throw new \InvalidArgumentException('Target gateway origin invalido.');
        }

        $token = $token->fresh();

        if ($token === null) {
            throw new \RuntimeException('Token local inexistente.');
        }

        $currentOrigin = EfevooTokenGatewayOriginPolicy::resolvedOrigin($token);

        if ($currentOrigin === $targetOrigin) {
            return $token;
        }

        if (! $this->isTransitionAllowed($currentOrigin, $targetOrigin, $auditContext)) {
            throw new \DomainException("Promocion de gateway origin bloqueada: {$currentOrigin} -> {$targetOrigin}");
        }

        $metadata = is_array($token->metadata) ? $token->metadata : [];
        $previousOrigin = $metadata['gateway_origin'] ?? null;

        $metadata['gateway_origin'] = $targetOrigin;
        $metadata['gateway_origin_previous'] = is_string($previousOrigin) && $previousOrigin !== ''
            ? $previousOrigin
            : $currentOrigin;
        $metadata['gateway_origin_promoted_at'] = now()->toIso8601String();
        $metadata['gateway_origin_promotion_source'] = (string) ($auditContext['source'] ?? 'unknown');

        if (isset($auditContext['attempt_id'])) {
            $metadata['gateway_origin_promotion_attempt_id'] = (int) $auditContext['attempt_id'];
        }

        if ($targetOrigin !== EfevooPayGatewayMode::MOCK) {
            unset($metadata['mock']);
        }

        $token->update([
            'metadata' => $metadata,
            'is_active' => true,
        ]);

        Log::info('[Efevoo] gateway_origin promoted', [
            'token_id' => $token->id,
            'customer_id' => $token->customer_id,
            'card_last_four' => $token->card_last_four,
            'previous_origin' => $metadata['gateway_origin_previous'],
            'new_origin' => $targetOrigin,
            'source' => $metadata['gateway_origin_promotion_source'],
            'attempt_id' => $auditContext['attempt_id'] ?? null,
        ]);

        return $token->fresh();
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function isTransitionAllowed(string $fromOrigin, string $toOrigin, array $auditContext = []): bool
    {
        if ($fromOrigin === $toOrigin) {
            return true;
        }

        if (in_array($fromOrigin, [EfevooPayGatewayMode::LIVE], true)
            && in_array($toOrigin, [EfevooPayGatewayMode::MOCK, EfevooPayGatewayMode::TEST], true)) {
            return false;
        }

        if ($fromOrigin === EfevooPayGatewayMode::TEST && $toOrigin === EfevooPayGatewayMode::MOCK) {
            return false;
        }

        if ($toOrigin === EfevooPayGatewayMode::MOCK) {
            return false;
        }

        $source = (string) ($auditContext['source'] ?? '');

        if ($fromOrigin === EfevooPayGatewayMode::MOCK && $toOrigin === EfevooPayGatewayMode::LIVE) {
            return in_array($source, ['tokencard', 'reconcile-tokenized-attempts'], true);
        }

        if ($fromOrigin === EfevooPayGatewayMode::TEST && $toOrigin === EfevooPayGatewayMode::LIVE) {
            return $source === 'reconcile-tokenized-attempts'
                && ($auditContext['explicit_test_to_live'] ?? false) === true;
        }

        return false;
    }
}

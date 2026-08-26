<?php

namespace App\Support;

use App\Models\EfevooToken;
use Illuminate\Database\Eloquent\Builder;

/**
 * Procedencia persistida de tokens EfevooPay (mock | test | live).
 *
 * Legacy sin gateway_origin (política documentada, sin heurísticas de alias/last4/token):
 * - metadata.mock = true  → origen mock; visible/cobrable solo en gateway mock.
 * - environment = production y mock ≠ true → origen live; visible/cobrable solo en gateway live.
 * - environment = test y mock ≠ true → origen test; visible/cobrable solo en gateway test
 *   (tokens locales ambiguos: requieren clasificación manual via efevoo:tokens:classify-gateway-origin).
 */
class EfevooTokenGatewayOriginPolicy
{
    public static function resolvedOrigin(EfevooToken $token): string
    {
        $metadata = is_array($token->metadata) ? $token->metadata : [];
        $persisted = $metadata['gateway_origin'] ?? null;

        if (is_string($persisted) && in_array($persisted, self::allowedOrigins(), true)) {
            return $persisted;
        }

        if (! empty($metadata['mock'])) {
            return EfevooPayGatewayMode::MOCK;
        }

        if ($token->environment === 'production') {
            return EfevooPayGatewayMode::LIVE;
        }

        return EfevooPayGatewayMode::TEST;
    }

    public static function hasPersistedOrigin(EfevooToken $token): bool
    {
        $metadata = is_array($token->metadata) ? $token->metadata : [];
        $persisted = $metadata['gateway_origin'] ?? null;

        return is_string($persisted)
            && $persisted !== ''
            && in_array($persisted, self::allowedOrigins(), true);
    }

    public static function isVisibleInGateway(EfevooToken $token, string $gatewayMode): bool
    {
        if (! in_array($gatewayMode, self::allowedOrigins(), true)) {
            return false;
        }

        return self::resolvedOrigin($token) === $gatewayMode;
    }

    public static function isChargeableInGateway(EfevooToken $token, string $gatewayMode): bool
    {
        if (! $token->is_active || $token->trashed() || $token->isExpired()) {
            return false;
        }

        return self::isVisibleInGateway($token, $gatewayMode);
    }

    public static function suggestedPersistedOrigin(EfevooToken $token): ?string
    {
        if (self::hasPersistedOrigin($token)) {
            return self::resolvedOrigin($token);
        }

        $metadata = is_array($token->metadata) ? $token->metadata : [];

        if (! empty($metadata['mock'])) {
            return EfevooPayGatewayMode::MOCK;
        }

        if ($token->environment === 'production') {
            return EfevooPayGatewayMode::LIVE;
        }

        if ($token->environment === 'test') {
            return EfevooPayGatewayMode::TEST;
        }

        return null;
    }

    public static function isAmbiguousLegacy(EfevooToken $token): bool
    {
        if (self::hasPersistedOrigin($token)) {
            return false;
        }

        $metadata = is_array($token->metadata) ? $token->metadata : [];

        return empty($metadata['mock']) && $token->environment === 'test';
    }

    public static function scopeCompatibleWithGateway(Builder $query, string $gatewayMode): Builder
    {
        if (! in_array($gatewayMode, self::allowedOrigins(), true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($gatewayMode) {
            $outer->where('metadata->gateway_origin', $gatewayMode)
                ->orWhere(function (Builder $legacy) use ($gatewayMode) {
                    $legacy->where(function (Builder $missingOrigin) {
                        $missingOrigin->whereNull('metadata->gateway_origin')
                            ->orWhere('metadata->gateway_origin', '');
                    })->where(function (Builder $policy) use ($gatewayMode) {
                        match ($gatewayMode) {
                            EfevooPayGatewayMode::MOCK => $policy->where('metadata->mock', true),
                            EfevooPayGatewayMode::LIVE => $policy->where('environment', 'production')
                                ->where(function (Builder $notMock) {
                                    $notMock->whereNull('metadata->mock')
                                        ->orWhere('metadata->mock', false);
                                }),
                            EfevooPayGatewayMode::TEST => $policy->where('environment', 'test')
                                ->where(function (Builder $notMock) {
                                    $notMock->whereNull('metadata->mock')
                                        ->orWhere('metadata->mock', false);
                                }),
                            default => $policy->whereRaw('1 = 0'),
                        };
                    });
                });
        });
    }

    /**
     * @return list<string>
     */
    public static function allowedOrigins(): array
    {
        return [
            EfevooPayGatewayMode::MOCK,
            EfevooPayGatewayMode::TEST,
            EfevooPayGatewayMode::LIVE,
        ];
    }
}

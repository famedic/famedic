<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\EfevooToken;
use App\Services\EfevooPay\MockEfevooPayGateway;

class MockEfevooPaymentSupport
{
    public static function isMockMode(): bool
    {
        return ! app()->environment('production');
    }

    /**
     * Valor permitido en efevoo_tokens.environment (enum: test | production).
     */
    public static function efevooTokenEnvironment(): string
    {
        return app()->environment('production') ? 'production' : 'test';
    }

    public static function isMockToken(EfevooToken $token): bool
    {
        $metadata = $token->metadata ?? [];

        if (! empty($metadata['mock'])) {
            return true;
        }

        if (str_starts_with((string) $token->card_token, 'mock_tok_')) {
            return true;
        }

        if (str_starts_with((string) $token->client_token, 'mock_clt_')) {
            return true;
        }

        if (str_contains(strtoupper((string) $token->card_holder), 'MOCK')) {
            return true;
        }

        if (str_contains(strtolower((string) $token->alias), 'mock')) {
            return true;
        }

        return false;
    }

    /**
     * Garantiza tokens de prueba en BD para el checkout (solo no-producción).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ensureTestTokensForCustomer(Customer $customer): array
    {
        if (! self::isMockMode()) {
            return [];
        }

        $paymentMethods = [];

        foreach (MockEfevooPayGateway::testCardDefinitions() as $definition) {
            $pan = preg_replace('/\D/', '', $definition['number']);
            $lastFour = substr($pan, -4);
            $expiration = $definition['exp_month'].$definition['exp_year'];

            $token = EfevooToken::query()
                ->where('customer_id', $customer->id)
                ->where('card_last_four', $lastFour)
                ->where('card_expiration', $expiration)
                ->where('environment', self::efevooTokenEnvironment())
                ->where('is_active', true)
                ->first();

            if (! $token) {
                $token = EfevooToken::create([
                    'customer_id' => $customer->id,
                    'card_token' => MockEfevooPayGateway::mockCardTokenForPan($pan),
                    'client_token' => 'mock_clt_'.substr(md5($pan.$customer->id), 0, 16),
                    'card_last_four' => $lastFour,
                    'card_expiration' => $expiration,
                    'card_holder' => $definition['card_holder'],
                    'card_brand' => $definition['brand'],
                    'alias' => $definition['alias'],
                    'environment' => self::efevooTokenEnvironment(),
                    'is_active' => true,
                    'metadata' => [
                        'mock' => true,
                        'scenario' => $definition['scenario'],
                    ],
                ]);
            }

            $paymentMethods[] = [
                'id' => $token->id,
                'object' => 'efevoo_token',
                'card' => [
                    'brand' => strtolower($token->card_brand),
                    'last4' => $token->card_last_four,
                    'exp_month' => substr($token->card_expiration, 0, 2),
                    'exp_year' => '20'.substr($token->card_expiration, 2, 2),
                    'exp_year_short' => substr($token->card_expiration, 2, 2),
                ],
                'billing_details' => [
                    'name' => $token->card_holder,
                ],
                'alias' => $token->alias,
                'metadata' => [
                    'environment' => $token->environment,
                    'mock' => true,
                    'scenario' => $definition['scenario'],
                    'description' => $definition['description'],
                    'expires_at' => $token->expires_at?->toISOString(),
                ],
            ];
        }

        return $paymentMethods;
    }

    /**
     * @param  array<int, array<string, mixed>>  $userTokens
     * @param  array<int, array<string, mixed>>  $mockTokens
     * @return array<int, array<string, mixed>>
     */
    public static function mergePaymentMethodsForCheckout(array $userTokens, array $mockTokens): array
    {
        if (! self::isMockMode() || $mockTokens === []) {
            return $userTokens;
        }

        $mockIds = collect($mockTokens)->pluck('id')->all();
        $filteredUser = array_values(array_filter(
            $userTokens,
            fn (array $method) => ! in_array($method['id'], $mockIds, true)
        ));

        return array_merge($mockTokens, $filteredUser);
    }
}

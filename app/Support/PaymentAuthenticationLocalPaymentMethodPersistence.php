<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;

class PaymentAuthenticationLocalPaymentMethodPersistence
{
    public function isListableForCustomer(EfevooToken $token, Customer $customer): bool
    {
        return $this->isListableForCustomerInGateway(
            $token,
            $customer,
            EfevooPayGatewayMode::current()
        );
    }

    public function isListableForCustomerInGateway(EfevooToken $token, Customer $customer, string $gatewayMode): bool
    {
        if ((int) $token->customer_id !== (int) $customer->id) {
            return false;
        }

        return EfevooToken::query()
            ->whereKey($token->id)
            ->where('customer_id', $customer->id)
            ->currentEnvironment()
            ->active()
            ->excludeMockInProduction()
            ->when(true, function ($query) use ($gatewayMode) {
                return EfevooTokenGatewayOriginPolicy::scopeCompatibleWithGateway($query, $gatewayMode);
            })
            ->exists();
    }

    public function tokenForSession(Efevoo3dsSession $session, Customer $customer): ?EfevooToken
    {
        if (! $session->efevoo_token_id) {
            return null;
        }

        return EfevooToken::query()
            ->whereKey($session->efevoo_token_id)
            ->where('customer_id', $customer->id)
            ->first();
    }

    public function sessionHasListableToken(Efevoo3dsSession $session, Customer $customer): bool
    {
        $token = $this->tokenForSession($session, $customer);

        return $token !== null && $this->isListableForCustomer($token, $customer);
    }

    /**
     * Tras TokenCard exitoso en gateway HTTP, alinea gateway_origin si la fila ya existía.
     */
    public function promoteToCurrentGateway(EfevooToken $token): EfevooToken
    {
        if (EfevooPayGatewayMode::usesMock()) {
            return $token->fresh();
        }

        $currentGateway = EfevooPayGatewayMode::current();

        if ($currentGateway === EfevooPayGatewayMode::MOCK) {
            return $token->fresh();
        }

        if (EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token->fresh(), $currentGateway)) {
            return $token->fresh();
        }

        return app(EfevooTokenGatewayOriginPromotion::class)->promote($token, $currentGateway, [
            'source' => 'tokencard',
        ]);
    }

    public function finalizeTokenAfterProviderSuccess(EfevooToken $token, Customer $customer): ?EfevooToken
    {
        if (! EfevooPayGatewayMode::usesMock()) {
            $token = $this->promoteToCurrentGateway($token);
        }

        if ($this->isListableForCustomer($token, $customer)) {
            return $token;
        }

        return null;
    }
}

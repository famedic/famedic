<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayInterface;
use App\Exceptions\InvalidPaymentMethodException;
use App\Support\PaymentMethodIdentifier;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public function __construct(
        private EfevooPaymentGateway $efevooGateway,
        private HeyBancoPaymentGateway $heyBancoGateway,
    ) {}

    public function defaultProvider(): string
    {
        $configured = config('payments.default_provider', 'efevoopay');

        if ($configured === 'hey_banco' && $this->heyBancoGateway->isEnabled()) {
            return 'hey_banco';
        }

        if (! config('payments.efevoopay_enabled', true) && $this->heyBancoGateway->isEnabled()) {
            return 'hey_banco';
        }

        if ($configured === 'hey_banco' && ! $this->heyBancoGateway->isEnabled()) {
            return $this->efevooGateway->isEnabled() ? 'efevoopay' : 'hey_banco';
        }

        return $configured;
    }

    public function forProvider(?string $provider = null): PaymentGatewayInterface
    {
        $provider = $provider ?: $this->defaultProvider();

        return match ($provider) {
            'efevoopay' => $this->efevooGateway,
            'hey_banco' => $this->heyBancoGateway,
            default => throw new InvalidArgumentException("Proveedor de pago no soportado: {$provider}"),
        };
    }

    public function forPaymentMethodId(string $paymentMethodId): PaymentGatewayInterface
    {
        if (in_array($paymentMethodId, ['odessa', 'paypal', 'coupon_balance'], true)) {
            throw new InvalidPaymentMethodException('Método de pago no pertenece a un gateway de tarjeta.');
        }

        if (PaymentMethodIdentifier::isHeyBanco($paymentMethodId)) {
            return $this->heyBancoGateway;
        }

        if (ctype_digit($paymentMethodId)) {
            if (! $this->efevooGateway->isEnabled()) {
                throw new InvalidPaymentMethodException(
                    (string) config('payments.legacy_efevoo_rejection_message')
                );
            }

            return $this->efevooGateway;
        }

        throw new InvalidPaymentMethodException('Método de pago no reconocido.');
    }

    /**
     * @return array<int, PaymentGatewayInterface>
     */
    public function enabledGateways(): array
    {
        $gateways = [];

        if ($this->efevooGateway->isEnabled()) {
            $gateways[] = $this->efevooGateway;
        }

        if ($this->heyBancoGateway->isEnabled()) {
            $gateways[] = $this->heyBancoGateway;
        }

        return $gateways;
    }
}

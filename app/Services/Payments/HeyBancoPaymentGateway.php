<?php

namespace App\Services\Payments;

use App\Actions\Payments\HeyBanco\ChargeHeyBancoTokenAction;
use App\Actions\Payments\HeyBanco\CreateHeyBancoTokenAction;
use App\Actions\Payments\HeyBanco\VerifyHeyBancoTransactionAction;
use App\Contracts\PaymentGatewayInterface;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Support\PaymentMethodIdentifier;

class HeyBancoPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private CreateHeyBancoTokenAction $createTokenAction,
        private ChargeHeyBancoTokenAction $chargeTokenAction,
        private VerifyHeyBancoTransactionAction $verifyAction,
        private HeyBancoClient $client,
    ) {}

    public function tokenize(Customer $customer, array $cardData): array
    {
        $paymentMethod = ($this->createTokenAction)($customer->user, $cardData);

        return [
            'payment_method_id' => PaymentMethodIdentifier::heyBancoPublicId($paymentMethod->id),
            'brand' => $paymentMethod->brand,
            'last4' => $paymentMethod->last4,
            'provider' => config('heybanco.provider_key'),
        ];
    }

    public function charge(
        Customer $customer,
        int $amountCents,
        string $paymentMethodId,
        ?string $reference = null
    ): Transaction {
        $publicId = PaymentMethodIdentifier::isHeyBanco($paymentMethodId)
            ? $paymentMethodId
            : PaymentMethodIdentifier::heyBancoPublicId((int) $paymentMethodId);

        return ($this->chargeTokenAction)($customer, $amountCents, $publicId, $reference);
    }

    public function verify(string $reference, ?string $mediaId = null): array
    {
        $user = auth()->user();

        if (! $user) {
            return ['supported' => false, 'message' => 'Usuario no autenticado'];
        }

        $verification = $this->verifyAction->byReference($user, $reference, $mediaId);

        return [
            'provider' => config('heybanco.provider_key'),
            'supported' => true,
            'approved' => $verification->status === 'approved',
            'verification' => $verification->toArray(),
        ];
    }

    public function refundOrCancel(Transaction $transaction): array
    {
        $reference = $transaction->gateway_transaction_id;

        if (! $reference) {
            return [
                'provider' => config('heybanco.provider_key'),
                'supported' => false,
                'message' => 'Sin referencia Banregio para cancelar.',
            ];
        }

        $response = $this->client->cancelByReference($reference);

        return [
            'provider' => config('heybanco.provider_key'),
            'supported' => true,
            'approved' => $response->isApproved(),
            'response' => $response->toArray(),
        ];
    }

    public function providerKey(): string
    {
        return config('heybanco.provider_key');
    }

    public function isEnabled(): bool
    {
        return (bool) config('heybanco.enabled', false);
    }
}

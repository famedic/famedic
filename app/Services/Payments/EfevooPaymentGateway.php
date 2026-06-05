<?php

namespace App\Services\Payments;

use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Contracts\EfevooPayGateway;
use App\Contracts\PaymentGatewayInterface;
use App\Exceptions\EfevooPaymentException;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class EfevooPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private EfevooPayGateway $efevooPayGateway,
        private ChargeEfevooPaymentMethodAction $chargeAction,
    ) {}

    public function tokenize(Customer $customer, array $cardData): array
    {
        $month = str_pad($cardData['exp_month'], 2, '0', STR_PAD_LEFT);
        $year = substr($cardData['exp_year'], -2);

        $result = $this->efevooPayGateway->tokenizeCard([
            'card_number' => preg_replace('/\D/', '', $cardData['card_number']),
            'exp_month' => $month,
            'exp_year' => $year,
            'cvv' => $cardData['cvv'],
            'card_holder' => $cardData['card_holder'] ?? '',
            'alias' => $cardData['alias'] ?? '',
        ], $customer->id);

        if (! ($result['success'] ?? false)) {
            throw new EfevooPaymentException($result['message'] ?? 'No se pudo tokenizar la tarjeta.');
        }

        $token = EfevooToken::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first();

        return [
            'payment_method_id' => (string) $token?->id,
            'brand' => $token?->card_brand,
            'last4' => $token?->card_last_four,
            'provider' => 'efevoopay',
        ];
    }

    public function charge(
        Customer $customer,
        int $amountCents,
        string $paymentMethodId,
        ?string $reference = null
    ): Transaction {
        return ($this->chargeAction)($customer, $amountCents, $paymentMethodId);
    }

    public function verify(string $reference, ?string $mediaId = null): array
    {
        Log::info('[EfevooPay] verify no implementado en gateway adapter', [
            'reference' => $reference,
        ]);

        return [
            'provider' => 'efevoopay',
            'supported' => false,
            'reference' => $reference,
        ];
    }

    public function refundOrCancel(Transaction $transaction): array
    {
        return [
            'provider' => 'efevoopay',
            'supported' => true,
            'transaction_id' => $transaction->id,
        ];
    }

    public function providerKey(): string
    {
        return 'efevoopay';
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

<?php

namespace App\Contracts;

use App\Models\Customer;
use App\Models\Transaction;

interface PaymentGatewayInterface
{
    /**
     * Tokeniza una tarjeta y persiste el método de pago.
     *
     * @param  array{card_number: string, exp_month: string, exp_year: string, cvv: string, card_holder?: string, alias?: string}  $cardData
     * @return array{payment_method_id: string, brand: ?string, last4: ?string, provider: string}
     */
    public function tokenize(Customer $customer, array $cardData): array;

    /**
     * Cobra usando un método de pago tokenizado previamente.
     *
     * @param  string  $paymentMethodId  ID interno del proveedor (ej. "hey_banco:5" o "12" para Efevoo)
     */
    public function charge(Customer $customer, int $amountCents, string $paymentMethodId, ?string $reference = null): Transaction;

    /**
     * Verifica el estado de una transacción en el gateway.
     *
     * @return array<string, mixed>
     */
    public function verify(string $reference, ?string $mediaId = null): array;

    /**
     * Cancela o reembolsa según soporte del proveedor.
     *
     * @return array<string, mixed>
     */
    public function refundOrCancel(Transaction $transaction): array;

    public function providerKey(): string;

    public function isEnabled(): bool;
}

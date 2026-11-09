<?php

namespace App\Services\MedicalCheckout;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Models\Transaction;

final class OdessaRemnantGateway implements MedicalRemnantGateway
{
    public function __construct(private ChargeOdessaAction $charge) {}

    public function start(Customer $c, Transaction $tx, string $ref, int $cash, string $method): PaymentObservation
    {
        if (! $c->customerable instanceof OdessaAfiliateAccount) {
            throw new \DomainException('Invalid Odessa account');
        }
        $payment = ($this->charge)($c->customerable, $cash, $tx);
        $data = $payment->gateway_response['response'] ?? [];
        if (($data['errorCode'] ?? null) !== 0 || PayPalRemnantGateway::cents($data['amount'] ?? null) !== $cash || ($data['currency'] ?? null) !== 'MXN' || (string) ($data['transaction'] ?? '') !== (string) $tx->id || empty($data['transactionOds'])) {
            return new PaymentObservation('unknown', $ref, $cash);
        }

        return new PaymentObservation('confirmed', $ref, $cash, (string) $data['transactionOds']);
    }

    public function observe(Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        return new PaymentObservation('unknown', $ref, $cash);
    }

    public function reverse(Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        // Current Odessa refund only sends notification; it is not financial confirmation.
        return new PaymentObservation('unknown',$ref,$cash);
    }
}

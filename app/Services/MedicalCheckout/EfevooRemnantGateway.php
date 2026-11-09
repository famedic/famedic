<?php

namespace App\Services\MedicalCheckout;

use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Models\Customer;
use App\Models\Transaction;

final class EfevooRemnantGateway implements MedicalRemnantGateway
{
    public function __construct(private ChargeEfevooPaymentMethodAction $charge) {}

    public function start(Customer $c, Transaction $tx, string $ref, int $cash, string $method): PaymentObservation
    {
        $this->tls();
        $payment = ($this->charge)($c, $cash, $method, $ref, $tx);
        $r = $payment->gateway_response;
        $data = $r['raw']['data'] ?? [];
        $amount = PayPalRemnantGateway::cents($data['amount'] ?? null);
        if (($r['success'] ?? null) !== true || $amount !== $cash || ($data['currency'] ?? null) !== 'MXN' || ($data['reference'] ?? null) !== $ref || ! is_string($r['transaction_id'] ?? null) || $r['transaction_id'] === '') {
            return new PaymentObservation('unknown', $ref, $cash);
        }

        return new PaymentObservation('confirmed', $ref, $cash, $r['transaction_id']);
    }

    public function observe(Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        $this->tls();
        if (! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $tx->gateway_transaction_id)) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $r = app(\App\Services\EfevooPayService::class)->searchTransactions(['transaction_id' => (int) $tx->gateway_transaction_id]);
        $rows = $r['data']['data'] ?? null;
        if (($r['success'] ?? null) !== true || ($r['simulated'] ?? false) === true || ! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $row = $rows[0];
        if ((string) ($row['id'] ?? '') !== (string) $tx->gateway_transaction_id || ($row['reference'] ?? null) !== $ref || PayPalRemnantGateway::cents($row['amount'] ?? null) !== $cash || ($row['currency'] ?? null) !== 'MXN') {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        if (($row['status'] ?? null) === 'COMPLETED') {
            return new PaymentObservation('confirmed', $ref, $cash, (string) $row['id']);
        }
        if (($row['status'] ?? null) === 'REFUNDED' && is_string($row['refund_id'] ?? null) && $row['refund_id'] !== '') {
            return new PaymentObservation('reversed', $ref, $cash, $row['refund_id']);
        }

        return new PaymentObservation('unknown', $ref, $cash);
    }

    public function reverse(Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        $this->tls();
        if (! ctype_digit((string) $tx->gateway_transaction_id)) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $r = app(\App\Services\EfevooPayService::class)->refundTransaction((int) $tx->gateway_transaction_id);
        $data = $r['data'] ?? [];
        if (($r['success'] ?? null) !== true || ($data['codigo'] ?? null) !== '00' || PayPalRemnantGateway::cents($data['amount'] ?? null) !== $cash || ($data['currency'] ?? null) !== 'MXN' || empty($data['refund_id'])) {
            return new PaymentObservation('unknown', $ref, $cash);
        }

        return new PaymentObservation('reversed', $ref, $cash, (string) $data['refund_id']);
    }

    private function tls(): void
    {
        if (config('efevoopay.verify_ssl') !== true) {
            throw new \App\Domain\Akubica\Benefits\BenefitFailure('GATEWAY_CERTIFICATION_PENDING');
        }
    }
}

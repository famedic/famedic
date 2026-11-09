<?php

namespace App\Services\MedicalCheckout;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\PayPalService;

final class PayPalRemnantGateway implements MedicalRemnantGateway
{
    public function __construct(private PayPalService $paypal) {}

    public function start(Customer $c, Transaction $tx, string $ref, int $cash, string $method): PaymentObservation
    {
        $order = $this->paypal->createOrder($cash / 100, 'MXN', $ref, 'Famedic membresía médica');

        return new PaymentObservation('pending', $ref, $cash, providerOrder: $order['order_id'] ?? null);
    }

    public static function cents(mixed $amount): ?int
    {
        if (! is_string($amount) || ! preg_match('/\A([0-9]{1,12})\.([0-9]{2})\z/D', $amount, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) $m[2];
    }

    public function observe(Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        if (! $tx->provider_order_id) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $order = $this->paypal->getOrder($tx->provider_order_id);
        $units = $order['purchase_units'] ?? [];
        if (($order['id'] ?? null) !== $tx->provider_order_id || count($units) !== 1) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $u = $units[0];
        $captures = $u['payments']['captures'] ?? [];
        $merchant = config('akubica_connectors.paypal_merchant_id');
        if (! is_string($merchant) || $merchant === '' || ($u['payee']['merchant_id'] ?? null) !== $merchant || ($u['custom_id'] ?? null) !== $ref || self::cents($u['amount']['value'] ?? null) !== $cash || ($u['amount']['currency_code'] ?? null) !== 'MXN' || count($captures) !== 1) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $p = $captures[0];
        if (($order['status'] ?? null) !== 'COMPLETED' || ($p['status'] ?? null) !== 'COMPLETED' || ($p['final_capture'] ?? null) !== true || self::cents($p['amount']['value'] ?? null) !== $cash || ($p['amount']['currency_code'] ?? null) !== 'MXN' || ! is_string($p['id'] ?? null) || $p['id'] === '') {
            return new PaymentObservation('unknown', $ref, $cash);
        }

        return new PaymentObservation('confirmed', $ref, $cash, $p['id']);
    }

    public function reverse(Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        if (! $tx->gateway_transaction_id) {
            return new PaymentObservation('unknown', $ref, $cash);
        }
        $p = $this->paypal->refund($tx->gateway_transaction_id, $cash / 100, 'MXN');
        if (($p['status'] ?? null) !== 'COMPLETED' || self::cents($p['amount']['value'] ?? null) !== $cash || ($p['amount']['currency_code'] ?? null) !== 'MXN' || ! is_string($p['id'] ?? null) || $p['id'] === '') {
            return new PaymentObservation('unknown',$ref,$cash);
        }

        return new PaymentObservation('reversed',$ref,$cash,$p['id']);
    }
}

<?php

namespace App\Support;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Log;

class PaymentMethodResolver
{
    public static function normalizeForCustomer(Customer $customer, string $paymentMethod): string
    {
        if (in_array($paymentMethod, ['odessa', 'paypal', 'coupon_balance'], true)) {
            return $paymentMethod;
        }

        if (PaymentMethodIdentifier::isHeyBanco($paymentMethod)) {
            return $paymentMethod;
        }

        if (! ctype_digit($paymentMethod)) {
            return $paymentMethod;
        }

        $heyBancoMethod = PaymentMethod::query()
            ->active()
            ->forProvider(config('heybanco.provider_key'))
            ->where('user_id', $customer->user_id)
            ->where('id', (int) $paymentMethod)
            ->first();

        if ($heyBancoMethod) {
            $normalized = PaymentMethodIdentifier::heyBancoPublicId($heyBancoMethod->id);

            Log::info('[Payments] Normalized numeric payment_method to Hey Banco public id', [
                'input' => $paymentMethod,
                'normalized' => $normalized,
            ]);

            return $normalized;
        }

        if (! config('payments.efevoopay_enabled', true)) {
            throw new HeyBancoPaymentException(
                (string) config('payments.legacy_efevoo_rejection_message')
            );
        }

        return $paymentMethod;
    }

    public static function detectProvider(string $paymentMethod): ?string
    {
        if (in_array($paymentMethod, ['odessa', 'paypal', 'coupon_balance'], true)) {
            return $paymentMethod;
        }

        if (PaymentMethodIdentifier::isHeyBanco($paymentMethod)) {
            return 'hey_banco';
        }

        if (ctype_digit($paymentMethod)) {
            return config('payments.efevoopay_enabled', true) ? 'efevoopay' : 'legacy_numeric';
        }

        return null;
    }

    public static function logSelection(string $paymentMethodInput, ?int $amountCents = null): void
    {
        Log::info('[Payments] selected payment method', [
            'input' => $paymentMethodInput,
            'provider_detected' => self::detectProvider($paymentMethodInput),
            'efevoopay_enabled' => config('payments.efevoopay_enabled'),
            'heybanco_enabled' => config('heybanco.enabled'),
            'heybanco_3ds_enabled' => config('heybanco.3ds_enabled'),
            'amount_cents' => $amountCents,
        ]);
    }
}

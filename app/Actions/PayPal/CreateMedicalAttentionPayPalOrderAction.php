<?php

namespace App\Actions\PayPal;

use App\Exceptions\PayPalPaymentException;
use App\Exceptions\PayPalRecoveryConfirmationPendingException;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\PayPalService;
use App\Support\PaymentAuthenticationRecoveryPayPalOrderHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateMedicalAttentionPayPalOrderAction
{
    public const DETAILS_PURPOSE = 'medical_attention_subscription';

    public function __construct(
        private PayPalService $payPalService,
        private PaymentAuthenticationRecoveryPayPalOrderHelper $recoveryPayPalOrderHelper,
    ) {}

    /**
     * @return array{order_id: string, transaction_id: int}
     */
    public function __invoke(Customer $customer, ?string $recoveryContextUuid = null): array
    {
        if ($customer->medicalAttentionSubscriptions()->active()->exists()) {
            throw new PayPalPaymentException('Ya tienes una membresía médica activa.');
        }

        $amountCents = (int) config('famedic.medical_attention_subscription_price_cents', 30000);
        if ($amountCents <= 0) {
            throw new PayPalPaymentException('Precio de membresía inválido.');
        }

        $amount = round($amountCents / 100, 2);

        $recoveryBootstrap = null;
        if ($recoveryContextUuid) {
            $recoveryBootstrap = $this->recoveryPayPalOrderHelper->bootstrapForOrder(
                $customer,
                $recoveryContextUuid,
                $amountCents,
                self::DETAILS_PURPOSE
            );

            if ($recoveryBootstrap['reused']) {
                return $recoveryBootstrap['reused'];
            }

            $this->recoveryPayPalOrderHelper->recordOrderRequestStarted(
                $recoveryBootstrap['context'],
                $recoveryBootstrap['attempt']
            );
        }

        $transaction = DB::transaction(function () use ($customer, $amountCents, $recoveryBootstrap) {
            $tempReference = 'PAYPAL-MA-PENDING-'.Str::uuid()->toString();

            $details = [
                'purpose' => self::DETAILS_PURPOSE,
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
            ];

            if ($recoveryBootstrap) {
                $details = $this->recoveryPayPalOrderHelper->mergeRecoveryDetails(
                    $details,
                    $recoveryBootstrap['context'],
                    $recoveryBootstrap['attempt']
                );
            }

            return Transaction::create([
                'transaction_amount_cents' => $amountCents,
                'payment_method' => 'paypal',
                'payment_provider' => 'paypal',
                'gateway' => 'paypal',
                'reference_id' => $tempReference,
                'payment_status' => 'pending',
                'details' => $details,
            ]);
        });

        $customId = 'ma-'.$transaction->id;

        try {
            $paypal = $this->payPalService->createOrder(
                $amount,
                'MXN',
                $customId,
                'Membresía médica anual Famedic'
            );
        } catch (\Throwable $e) {
            if ($recoveryBootstrap) {
                $this->recoveryPayPalOrderHelper->markCreateTimeout(
                    $recoveryBootstrap['context'],
                    $transaction,
                    $recoveryBootstrap['attempt']
                );

                if (! $e instanceof PayPalPaymentException) {
                    throw new PayPalRecoveryConfirmationPendingException(
                        supportReference: $recoveryBootstrap['attempt']->support_reference,
                    );
                }
            }

            throw $e;
        }

        $transaction->update([
            'reference_id' => $paypal['order_id'],
            'provider_order_id' => $paypal['order_id'],
            'raw_response' => $paypal['raw'],
            'gateway_response' => $paypal['raw'],
        ]);

        Log::info('[PayPal][MedicalAttention] Orden creada', [
            'transaction_id' => $transaction->id,
            'paypal_order_id' => $paypal['order_id'],
            'customer_id' => $customer->id,
            'recovery_context_uuid' => $recoveryContextUuid,
        ]);

        if ($recoveryBootstrap) {
            $this->recoveryPayPalOrderHelper->afterOrderCreated(
                $recoveryBootstrap['context'],
                $transaction->fresh(),
                $recoveryBootstrap['attempt']
            );
        }

        return [
            'order_id' => $paypal['order_id'],
            'transaction_id' => $transaction->id,
        ];
    }
}

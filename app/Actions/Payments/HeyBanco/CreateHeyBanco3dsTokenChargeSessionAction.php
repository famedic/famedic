<?php

namespace App\Actions\Payments\HeyBanco;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\Customer;
use App\Models\Payment3dsSession;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Support\PaymentMethodIdentifier;
use Illuminate\Database\Eloquent\Model;

class CreateHeyBanco3dsTokenChargeSessionAction
{
    public function __construct(
        private HeyBancoClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $checkoutContext
     */
    public function __invoke(
        Customer $customer,
        string $paymentMethodId,
        int $amountCents,
        array $checkoutContext = [],
        ?Model $related = null,
    ): Payment3dsSession {
        if (! config('heybanco.3ds_enabled', false)) {
            throw new HeyBancoPaymentException('El flujo 3DS de Hey Banco no está habilitado.');
        }

        if (! PaymentMethodIdentifier::isHeyBanco($paymentMethodId)) {
            throw new HeyBancoPaymentException('Método de pago Hey Banco inválido.');
        }

        $methodId = PaymentMethodIdentifier::heyBancoId($paymentMethodId);

        $paymentMethod = PaymentMethod::query()
            ->active()
            ->forProvider(config('heybanco.provider_key'))
            ->where('user_id', $customer->user_id)
            ->where('id', $methodId)
            ->first();

        if (! $paymentMethod) {
            throw new HeyBancoPaymentException(
                'El método de pago seleccionado no está disponible o ha expirado.'
            );
        }

        if ($paymentMethod->isExpired()) {
            throw new HeyBancoPaymentException(
                'El método de pago ha expirado. Por favor selecciona otro.'
            );
        }

        if (empty($paymentMethod->provider_token)) {
            throw new HeyBancoPaymentException(
                'El token de tarjeta no está disponible. La tarjeta necesita ser tokenizada nuevamente.'
            );
        }

        $reference = $checkoutContext['reference']
            ?? ('FM-3DS-' . $customer->id . '-' . time() . '-' . rand(1000, 9999));
        $amount = $amountCents / 100;
        $folio = $this->generateFolio();

        $attempt = PaymentAttempt::create([
            'customer_id' => $customer->id,
            'token_id' => $paymentMethod->id,
            'amount_cents' => $amountCents,
            'gateway' => config('heybanco.provider_key'),
            'reference' => $reference,
            'status' => 'pending_3ds',
        ]);

        $paymentTransaction = PaymentTransaction::create([
            'user_id' => $customer->user_id,
            'payment_method_id' => $paymentMethod->id,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'provider' => config('heybanco.provider_key'),
            'flow' => 'token_3ds_charge',
            'folio' => $folio,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => config('heybanco.currency', 'MXN'),
            'mode' => config('heybanco.mode'),
            'status' => 'pending_3ds',
        ]);

        return Payment3dsSession::create([
            'user_id' => $customer->user_id,
            'customer_id' => $customer->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_attempt_id' => $attempt->id,
            'payment_transaction_id' => $paymentTransaction->id,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'provider' => config('heybanco.provider_key'),
            'flow' => 'token_3ds_charge',
            'folio' => $folio,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => config('heybanco.currency', 'MXN'),
            'mode' => config('heybanco.mode'),
            'status' => 'pending',
            'response_url' => route('payments.hey-banco.3ds.callback'),
            'checkout_context' => $checkoutContext,
            'expires_at' => now()->addMinutes((int) config('heybanco.3ds_timeout', 30)),
        ]);
    }

    private function generateFolio(): string
    {
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $folio = $this->client->generateFolio();

            $existsInTransactions = PaymentTransaction::query()
                ->where('folio', $folio)
                ->where('provider', config('heybanco.provider_key'))
                ->exists();

            $existsInSessions = Payment3dsSession::query()
                ->where('folio', $folio)
                ->where('provider', config('heybanco.provider_key'))
                ->exists();

            if (! $existsInTransactions && ! $existsInSessions) {
                return $folio;
            }
        }

        return $this->client->generateFolio();
    }
}

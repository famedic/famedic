<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\MockEfevooPaymentSupport;
use App\Support\PaymentAuthenticationRecoveryContextManager;
use App\Support\PaymentAuthenticationRecoveryPayPalNavigator;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicalAttentionCheckoutController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $customer = $request->user()->customer;

        if ($customer->medical_attention_subscription_is_active) {
            return redirect()->route('medical-attention');
        }

        $recoveryRedirect = $this->resolveRecoveryPayPalRedirect($request, $customer);
        if ($recoveryRedirect) {
            return $recoveryRedirect;
        }

        $recoveryPayPal = $this->resolveRecoveryPayPalPresentation($request, $customer);

        $mockTokens = MockEfevooPaymentSupport::isMockMode()
            ? MockEfevooPaymentSupport::ensureTestTokensForCustomer($customer)
            : [];

        return Inertia::render('MedicalAttention/Checkout', [
            'formattedPrice' => formattedCentsPrice(config('famedic.medical_attention_subscription_price_cents')),
            'priceCents' => config('famedic.medical_attention_subscription_price_cents'),
            'paymentMethods' => $this->resolvePaymentMethods($customer, $mockTokens),
            'paymentUsesMock' => MockEfevooPaymentSupport::isMockMode(),
            'hasOdessaPay' => $customer->has_odessa_afiliate_account,
            'hasOdessaAfiliateAccount' => $customer->has_odessa_afiliate_account,
            'hasPayPal' => (bool) config('services.paypal.client_id'),
            'paypalClientId' => config('services.paypal.client_id'),
            'checkoutReturnUrl' => route('medical-attention.checkout'),
            'recoveryPayPal' => $recoveryPayPal,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRecoveryPayPalPresentation(Request $request, Customer $customer): ?array
    {
        if ($request->query('recovery_payment') !== 'paypal' && ! $request->filled('recovery_context_uuid')) {
            return null;
        }

        $navigator = app(PaymentAuthenticationRecoveryPayPalNavigator::class);
        $prepared = $navigator->consumePreparedCheckout($customer);
        $uuid = $request->query('recovery_context_uuid') ?: ($prepared['recovery_context_uuid'] ?? null);

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $context = app(PaymentAuthenticationRecoveryContextManager::class)->findOwned($customer, $uuid);

        if (! $context || $context->status === PaymentAuthenticationRecoveryContextStatus::Recovered) {
            return null;
        }

        return [
            'context_uuid' => $context->context_uuid,
            'highlight' => true,
            'selected_payment_method' => 'paypal',
        ];
    }

    private function resolveRecoveryPayPalRedirect(Request $request, Customer $customer): ?RedirectResponse
    {
        $uuid = $request->query('recovery_context_uuid');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $context = app(PaymentAuthenticationRecoveryContextManager::class)->findOwned($customer, $uuid);

        if (! $context || $context->status !== PaymentAuthenticationRecoveryContextStatus::Recovered) {
            return null;
        }

        if ($customer->medical_attention_subscription_is_active) {
            return redirect()->route('medical-attention');
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mockTokens
     * @return array<int, array<string, mixed>>
     */
    private function resolvePaymentMethods(Customer $customer, array $mockTokens = []): array
    {
        $userTokens = $customer->getEfevooPaymentMethods();

        return MockEfevooPaymentSupport::mergePaymentMethodsForCheckout($userTokens, $mockTokens);
    }
}

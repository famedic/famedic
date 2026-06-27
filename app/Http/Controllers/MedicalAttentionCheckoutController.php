<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\MockEfevooPaymentSupport;
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
        ]);
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

<?php

namespace App\Http\Requests\Admin\PaymentAuthenticationAttempts;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Support\EfevooPay3dsResultClassifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPaymentAuthenticationAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->administrator?->hasPermissionTo('payment-attempts.manage');
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['1d', '7d', '30d', 'custom'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(array_column(PaymentAuthenticationAttemptStatus::cases(), 'value'))],
            'result_category' => ['nullable', Rule::in($this->categories())],
            'failure_origin' => ['nullable', Rule::in($this->origins())],
            'failure_certainty' => ['nullable', Rule::in([
                EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                EfevooPay3dsResultClassifier::CERTAINTY_PROBABLE,
                EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
            ])],
            'provider' => ['nullable', 'string', 'max:80'],
            'attempt_uuid' => ['nullable', 'uuid'],
            'support_reference' => ['nullable', 'string', 'max:80'],
            'merchant_reference' => ['nullable', 'string', 'max:80'],
            'provider_order_id' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'string', 'max:255'],
            'has_retries' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'has_duplicates' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'has_timeout' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'has_technical_error' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'active' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'terminal' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'recovered_chain' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'outcome' => ['nullable', Rule::in([
                'expired_cancelled',
                'unknown_pending',
            ])],
            'recovery_context_type' => ['nullable', Rule::in(PaymentAuthenticationRecoveryContextType::values())],
            'recovery_context_status' => ['nullable', Rule::in(PaymentAuthenticationRecoveryContextStatus::values())],
            'recovery_method' => ['nullable', Rule::in(['paypal'])],
            'recovery_eligible' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'recovery_started' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'authentication_recovered' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'payment_recovered' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'selected_retry' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'selected_different_card' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'selected_paypal' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'recovery_confirmation_pending' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'limit_reached' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'legacy_only' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'exclude_legacy' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'multiple_get_link' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'multiple_token_card' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'tokenization_confirmation_pending' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'possible_duplicate_operation' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
            'excessive_get_status' => ['nullable', Rule::in(['1', '0', 1, 0, true, false])],
        ];
    }

    /**
     * @return list<string>
     */
    private function categories(): array
    {
        return [
            EfevooPay3dsResultClassifier::CATEGORY_ISSUER_DECLINED,
            EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
            EfevooPay3dsResultClassifier::CATEGORY_CANCELLED,
            EfevooPay3dsResultClassifier::CATEGORY_CANCELLED_BY_USER,
            EfevooPay3dsResultClassifier::CATEGORY_CANCELLED_BY_PROVIDER,
            EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED,
            EfevooPay3dsResultClassifier::CATEGORY_CARD_NOT_SUPPORTED,
            EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT,
            EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_ERROR,
            EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_UNAVAILABLE,
            EfevooPay3dsResultClassifier::CATEGORY_NETWORK_ERROR,
            EfevooPay3dsResultClassifier::CATEGORY_CONFIGURATION_ERROR,
            EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED,
            EfevooPay3dsResultClassifier::CATEGORY_DUPLICATE_REQUEST,
            EfevooPay3dsResultClassifier::CATEGORY_CONCURRENT_ATTEMPT,
            EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
        ];
    }

    /**
     * @return list<string>
     */
    private function origins(): array
    {
        return [
            EfevooPay3dsResultClassifier::ORIGIN_USER,
            EfevooPay3dsResultClassifier::ORIGIN_ISSUER,
            EfevooPay3dsResultClassifier::ORIGIN_ACS,
            EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY,
            EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
            EfevooPay3dsResultClassifier::ORIGIN_NETWORK,
            EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
        ];
    }
}

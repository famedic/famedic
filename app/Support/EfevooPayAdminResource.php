<?php

namespace App\Support;

use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EfevooPayAdminResource
{
    public static function paymentAttempt(PaymentAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'customer_id' => $attempt->customer_id,
            'customer' => $attempt->relationLoaded('customer') ? $attempt->customer : null,
            'token_id' => $attempt->token_id,
            'cart_id' => $attempt->cart_id,
            'amount_cents' => $attempt->amount_cents,
            'gateway' => $attempt->gateway,
            'reference' => $attempt->reference,
            'status' => $attempt->status,
            'processor_code' => $attempt->processor_code,
            'processor_message' => EfevooPayPersistenceNormalizer::message($attempt->processor_message),
            'processor_transaction_id' => $attempt->processor_transaction_id,
            'retry_count' => $attempt->retry_count,
            'processed_at' => $attempt->processed_at,
            'created_at' => $attempt->created_at,
            'updated_at' => $attempt->updated_at,
            'diagnostic' => EfevooPayPersistenceNormalizer::paymentResult($attempt->raw_response, 'payment_attempt'),
        ];
    }

    public static function transaction(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'transaction_amount_cents' => $transaction->transaction_amount_cents,
            'formatted_amount' => $transaction->formatted_amount,
            'formatted_created_at' => $transaction->formatted_created_at,
            'efevoo_commission_breakdown' => $transaction->efevoo_commission_breakdown,
            'payment_method' => $transaction->payment_method,
            'payment_provider' => $transaction->payment_provider,
            'reference_id' => $transaction->reference_id,
            'provider_order_id' => $transaction->provider_order_id,
            'provider_transaction_id' => $transaction->provider_transaction_id,
            'payment_status' => $transaction->payment_status,
            'description' => $transaction->description,
            'gateway' => $transaction->gateway,
            'gateway_transaction_id' => $transaction->gateway_transaction_id,
            'gateway_status' => $transaction->gateway_status,
            'gateway_processed_at' => $transaction->gateway_processed_at,
            'details' => self::safeTransactionDetails($transaction->details ?? []),
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ];
    }

    public static function efevooToken(EfevooToken $token, bool $includeTransactions = false): array
    {
        return [
            'id' => $token->id,
            'alias' => $token->alias,
            'card_last_four' => $token->card_last_four,
            'card_brand' => $token->card_brand,
            'card_expiration' => $token->card_expiration,
            'formatted_expiration' => $token->formatted_expiration,
            'customer_id' => $token->customer_id,
            'customer' => $token->relationLoaded('customer') ? $token->customer : null,
            'environment' => $token->environment,
            'formatted_environment' => $token->environment === 'production' ? 'Produccion' : 'Pruebas',
            'expires_at' => $token->expires_at,
            'is_active' => $token->is_active,
            'is_expired' => $token->isExpired(),
            'created_at' => $token->created_at,
            'updated_at' => $token->updated_at,
            'transactions' => $includeTransactions && $token->relationLoaded('transactions')
                ? $token->transactions->map(fn (EfevooTransaction $transaction) => self::efevooTransaction($transaction))->values()
                : null,
        ];
    }

    public static function efevooTransaction(EfevooTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'response_code' => $transaction->response_code,
            'response_message' => EfevooPayPersistenceNormalizer::message($transaction->response_message),
            'transaction_type' => $transaction->transaction_type,
            'cav' => $transaction->cav,
            'msi' => $transaction->msi,
            'processed_at' => $transaction->processed_at,
            'created_at' => $transaction->created_at,
        ];
    }

    public static function transformPaginator(LengthAwarePaginator $paginator, callable $callback): LengthAwarePaginator
    {
        $paginator->getCollection()->transform($callback);

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function safeTransactionDetails(array $details): array
    {
        return collect($details)
            ->only([
                'description',
                'customer_id',
                'customer_info',
                'token_info',
                'payment_details',
                'commission_cents',
                'commission_fetched_at',
                'coupon_amount_cents',
                'coupon_discount_cents',
                'original_total_cents',
                'processed_at',
                'simulated',
            ])
            ->map(function ($value, string $key) {
                if ($key === 'payment_details' && is_array($value)) {
                    return collect($value)->only([
                        'amount_cents',
                        'amount_mxn',
                        'reference',
                        'authorization_code',
                        'message',
                        'token_type_used',
                        'commission_source',
                    ])->all();
                }

                if ($key === 'token_info' && is_array($value)) {
                    return collect($value)->only([
                        'token_id',
                        'card_brand',
                        'card_last_four',
                        'alias',
                        'environment',
                        'expires_at',
                    ])->all();
                }

                return $value;
            })
            ->all();
    }
}

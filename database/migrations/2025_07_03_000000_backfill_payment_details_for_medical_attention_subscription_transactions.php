<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Transaction;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Log::info('Backfilling payment details for medical attention subscription transactions...');

        // Get all transaction IDs linked to medical attention subscriptions
        $transactionIds = DB::table('transactionables')
            ->where('transactionable_type', 'App\\Models\\MedicalAttentionSubscription')
            ->pluck('transaction_id');

        if ($transactionIds->isEmpty()) {
            Log::info('No transactions found for medical attention subscriptions');
            return;
        }

        // Get transactions that need payment details backfilled
        $transactions = Transaction::withTrashed()
            ->whereIn('id', $transactionIds)
            ->where(function ($query) {
                $query->whereNull('details')
                    ->orWhere('details->migrated_from_old_transaction_id', '!=', null);
            })
            ->get();

        Log::info("Found {$transactions->count()} medical attention subscription transactions to backfill");

        Stripe::setApiKey(config('services.stripe.secret'));

        foreach ($transactions as $transaction) {
            try {
                // Preserve existing migration metadata
                $existingDetails = $transaction->details ?? [];
                $newDetails = $existingDetails;

                if ($transaction->payment_method === 'stripe') {
                    // Try to get Stripe payment details
                    try {
                        $intent = PaymentIntent::retrieve($transaction->reference_id);
                        if ($intent && $intent->payment_method) {
                            $method = PaymentMethod::retrieve($intent->payment_method);
                            if ($method && $method->card) {
                                $newDetails = array_merge($newDetails, [
                                    'card_brand' => $method->card->brand,
                                    'card_last_four' => $method->card->last4,
                                    'payment_method_id' => $method->id,
                                ]);
                                Log::info("Backfilled Stripe details for transaction {$transaction->id}");
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to retrieve Stripe details for transaction {$transaction->id}: {$e->getMessage()}");
                        // Keep the transaction as is, but log the failure
                    }
                } elseif ($transaction->payment_method === 'odessa') {
                    // For ODESSA payments, ensure we have the payment method marked
                    // The PaymentMethodBadge will handle ODESSA display
                    Log::info("ODESSA transaction {$transaction->id} - no additional details needed");
                }

                // Save updated details if they changed
                if ($newDetails !== $existingDetails) {
                    $transaction->details = $newDetails;
                    $transaction->save();
                    Log::info("Updated details for transaction {$transaction->id}");
                }

            } catch (\Exception $e) {
                Log::error("Failed to process transaction {$transaction->id}: {$e->getMessage()}");
                continue;
            }
        }

        Log::info('Completed backfilling payment details for medical attention subscription transactions');
    }

    public function down(): void
    {
        Log::info('No rollback needed - payment details were backfilled for existing records');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Transaction;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Illuminate\Support\Facades\Log;

return new class extends Migration {
    public function up(): void
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        // Fetch soft-deleted transactions that have payment_method = 'stripe' and no details
        $deletedTransactions = Transaction::onlyTrashed()
            ->where('payment_method', 'stripe')
            ->whereNull('details')
            ->get();

        Log::info("Found {$deletedTransactions->count()} soft-deleted Stripe transactions to backfill");

        foreach ($deletedTransactions as $transaction) {
            try {
                $intent = PaymentIntent::retrieve($transaction->reference_id);
                if ($intent && $intent->payment_method) {
                    $method = PaymentMethod::retrieve($intent->payment_method);
                    if ($method && $method->card) {
                        $transaction->details = [
                            'card_brand' => $method->card->brand,
                            'card_last_four' => $method->card->last4,
                            'payment_method_id' => $method->id,
                        ];
                        $transaction->save();
                        Log::info("Backfilled details for soft-deleted transaction {$transaction->id}");
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to backfill details for soft-deleted transaction {$transaction->id}: {$e->getMessage()}");
                continue;
            }
        }

        Log::info("Completed backfilling Stripe details for soft-deleted transactions");
    }

    public function down(): void
    {
        // We don't need to undo this since we're just filling in missing data
        // The details column was already added in the previous migration
        Log::info("No action needed for rollback - details were backfilled for existing records");
    }
};

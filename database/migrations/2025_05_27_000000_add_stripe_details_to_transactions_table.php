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
        Schema::table('transactions', function (Blueprint $table) {
            $table->json('details')->nullable()->after('reference_id');
        });

        Stripe::setApiKey(config('services.stripe.secret'));
        $transactions = Transaction::where('payment_method', 'stripe')->get();
        foreach ($transactions as $transaction) {
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
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to backfill details for transaction {$transaction->id}: {$e->getMessage()}");
                continue;
            }
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};

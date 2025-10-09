<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('Migrating missing subscription invoices from old database...');

        DB::connection('mysqlold')
            ->table('subscription_invoices')
            ->orderBy('id')
            ->chunk(100, function ($invoices) {
                foreach ($invoices as $invoice) {
                    // Check if customer exists in new database
                    $customer = DB::connection('mysql')
                        ->table('customers')
                        ->where('id', $invoice->customer_id)
                        ->first();

                    if (! $customer) {
                        Log::warning("Skipping subscription invoice {$invoice->id} - customer {$invoice->customer_id} not found");

                        continue;
                    }

                    // Create new subscription - always create new ones, don't check for existing
                    $subscriptionId = DB::connection('mysql')
                        ->table('medical_attention_subscriptions')
                        ->insertGetId([
                            'customer_id' => $customer->id,
                            'start_date' => $invoice->period_start,
                            'end_date' => $invoice->period_end,
                            'price_cents' => $invoice->amount - $invoice->subsidy,
                            'created_at' => $invoice->created_at,
                            'updated_at' => $invoice->updated_at,
                            'deleted_at' => $invoice->deleted_at,
                        ]);

                    // Get transactions from old DB for this subscription invoice
                    $oldTransactions = DB::connection('mysqlold')
                        ->table('transactionables')
                        ->join('transactions', 'transactionables.transaction_id', '=', 'transactions.id')
                        ->where('transactionables.transactionable_type', 'App\\Models\\SubscriptionInvoice')
                        ->where('transactionables.transactionable_id', $invoice->id)
                        ->select('transactions.*')
                        ->get();

                    foreach ($oldTransactions as $oldTransaction) {
                        // Create NEW transaction (don't preserve old IDs)
                        $newTransactionId = DB::connection('mysql')
                            ->table('transactions')
                            ->insertGetId([
                                'transaction_amount_cents' => $oldTransaction->transaction_amount,
                                'payment_method' => $oldTransaction->payment_method,
                                'reference_id' => $oldTransaction->reference_id,
                                'details' => json_encode([
                                    'migrated_from_old_transaction_id' => $oldTransaction->id,
                                    'migrated_from_subscription_invoice_id' => $invoice->id,
                                ]),
                                'created_at' => $oldTransaction->created_at,
                                'updated_at' => $oldTransaction->updated_at,
                                'deleted_at' => $oldTransaction->deleted_at,
                            ]);

                        // Link NEW transaction to NEW subscription
                        DB::connection('mysql')
                            ->table('transactionables')
                            ->insert([
                                'transaction_id' => $newTransactionId,
                                'transactionable_type' => 'App\Models\MedicalAttentionSubscription',
                                'transactionable_id' => $subscriptionId,
                            ]);
                    }
                }
            });

        Log::info('Missing subscription invoices migration completed');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data migration - no rollback
    }
};

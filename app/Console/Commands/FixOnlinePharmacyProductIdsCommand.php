<?php

namespace App\Console\Commands;

use App\Actions\OnlinePharmacy\FetchOrderAction;
use App\Models\OnlinePharmacyPurchase;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixOnlinePharmacyProductIdsCommand extends Command
{
    protected $signature = 'famedic:fix-pharmacy-product-ids {--dry-run : Run without making changes}';

    protected $description = 'Fix vitau_product_id in OnlinePharmacyPurchaseItems by fetching correct data from Vitau API';

    private FetchOrderAction $fetchOrderAction;

    public function __construct(FetchOrderAction $fetchOrderAction)
    {
        parent::__construct();
        $this->fetchOrderAction = $fetchOrderAction;
    }

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('🔍 Running in DRY RUN mode - no changes will be made');
        }

        $totalPurchases = OnlinePharmacyPurchase::count();
        $this->info("Found {$totalPurchases} online pharmacy purchases to process");

        if ($totalPurchases === 0) {
            $this->info('No purchases to process. Exiting.');
            return;
        }

        $processed = 0;
        $updated = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($totalPurchases);
        $progressBar->start();

        // Use chunking like the migration pattern - natural rate limiting
        OnlinePharmacyPurchase::with('onlinePharmacyPurchaseItems')
            ->chunk(10, function ($purchases) use (&$processed, &$updated, &$errors, $isDryRun, $progressBar) {
                foreach ($purchases as $purchase) {
                    try {
                        $result = $this->processPurchase($purchase, $isDryRun);

                        if ($result['updated']) {
                            $updated++;
                        }

                        $processed++;
                        $progressBar->advance();
                    } catch (Exception $e) {
                        $errors++;
                        $this->error("\nError processing purchase ID {$purchase->id}: " . $e->getMessage());
                        Log::error('FixOnlinePharmacyProductIdsCommand error', [
                            'purchase_id' => $purchase->id,
                            'vitau_order_id' => $purchase->vitau_order_id,
                            'error' => $e->getMessage()
                        ]);
                        $progressBar->advance();
                    }
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info("✅ Processing complete!");
        $this->table(['Metric', 'Count'], [
            ['Processed', $processed],
            ['Updated', $updated],
            ['Errors', $errors],
            ['Total', $totalPurchases]
        ]);

        if ($isDryRun) {
            $this->warn('This was a DRY RUN - no actual changes were made.');
            $this->info('Run without --dry-run to apply the fixes.');
        }
    }

    private function processPurchase(OnlinePharmacyPurchase $purchase, bool $isDryRun): array
    {
        $this->line("\nProcessing purchase ID {$purchase->id} (Vitau Order: {$purchase->vitau_order_id})");

        // Fetch order details from Vitau API
        $orderData = ($this->fetchOrderAction)($purchase->vitau_order_id);

        if (!isset($orderData['details']) || !is_array($orderData['details'])) {
            throw new Exception("Invalid order data structure - missing details array");
        }

        $itemsUpdated = 0;

        // Process each purchase item
        foreach ($purchase->onlinePharmacyPurchaseItems as $purchaseItem) {
            // Find the matching Vitau item by comparing our current vitau_product_id with Vitau's ['id']
            $matchingVitauItem = collect($orderData['details'])->firstWhere('id', $purchaseItem->vitau_product_id);

            if (!$matchingVitauItem) {
                $this->warn("  - No matching Vitau item found for purchase item {$purchaseItem->id} (vitau_product_id: {$purchaseItem->vitau_product_id})");
                continue;
            }

            $correctProductId = $matchingVitauItem['product']['id'] ?? null;

            if (!$correctProductId) {
                $this->warn("  - No product.id found in Vitau data for purchase item {$purchaseItem->id}");
                continue;
            }

            // Check if update is needed
            if ($purchaseItem->vitau_product_id !== $correctProductId) {
                $this->info("  - Item {$purchaseItem->id}: {$purchaseItem->vitau_product_id} → {$correctProductId}");

                if (!$isDryRun) {
                    $purchaseItem->update(['vitau_product_id' => $correctProductId]);
                }

                $itemsUpdated++;
            } else {
                $this->line("  - Item {$purchaseItem->id}: Already correct ({$correctProductId})");
            }
        }

        return [
            'updated' => $itemsUpdated > 0,
            'items_updated' => $itemsUpdated
        ];
    }
}

<?php

namespace App\Services\Admin;

use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignCollectionItem;
use App\Models\MarketingCampaignLinkProduct;
use Illuminate\Support\Facades\Schema;

class LaboratoryTestAdminStatsService
{
    public function forTest(LaboratoryTest $laboratoryTest): array
    {
        $productId = (string) $laboratoryTest->id;
        $gdaId = (string) $laboratoryTest->gda_id;
        $since = now()->subDays(30);

        $purchaseItemsQuery = LaboratoryPurchaseItem::query()
            ->where('gda_id', $gdaId);

        $totalSales = (clone $purchaseItemsQuery)->count();
        $salesLast30Days = (clone $purchaseItemsQuery)
            ->where('created_at', '>=', $since)
            ->count();

        $totalRevenueCents = (int) (clone $purchaseItemsQuery)->sum('price_cents');
        $revenueLast30DaysCents = (int) (clone $purchaseItemsQuery)
            ->where('created_at', '>=', $since)
            ->sum('price_cents');

        return [
            'monitoring_active_carts' => $this->monitoringActiveCartsCount($productId),
            'legacy_customer_carts' => LaboratoryCartItem::query()
                ->where('laboratory_test_id', $laboratoryTest->id)
                ->count(),
            'total_sales' => $totalSales,
            'sales_last_30_days' => $salesLast30Days,
            'total_revenue_cents' => $totalRevenueCents,
            'revenue_last_30_days_cents' => $revenueLast30DaysCents,
            'formatted_total_revenue' => formattedCentsPrice($totalRevenueCents),
            'formatted_revenue_last_30_days' => formattedCentsPrice($revenueLast30DaysCents),
            'marketing_collections' => MarketingCampaignCollectionItem::query()
                ->where('laboratory_test_id', $laboratoryTest->id)
                ->count(),
            'marketing_links' => MarketingCampaignLinkProduct::query()
                ->where('laboratory_test_id', $laboratoryTest->id)
                ->count(),
            'recent_purchases' => $this->recentPurchases($gdaId),
            'recent_monitoring_carts' => $this->recentMonitoringCarts($productId),
        ];
    }

    private function monitoringActiveCartsCount(string $productId): int
    {
        if (! Schema::hasTable('carts') || ! Schema::hasTable('cart_items')) {
            return 0;
        }

        return Cart::query()
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereHas('items', fn ($query) => $query->where('product_id', $productId))
            ->count();
    }

    /**
     * @return list<array{id: int, laboratory_purchase_id: int, customer_name: string|null, created_at: string|null, formatted_price: string|null}>
     */
    private function recentPurchases(string $gdaId): array
    {
        return LaboratoryPurchaseItem::query()
            ->with(['laboratoryPurchase:id,name,paternal_lastname,maternal_lastname,created_at'])
            ->where('gda_id', $gdaId)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (LaboratoryPurchaseItem $item) => [
                'id' => $item->id,
                'laboratory_purchase_id' => $item->laboratory_purchase_id,
                'customer_name' => $item->laboratoryPurchase?->full_name,
                'created_at' => $item->laboratoryPurchase?->created_at?->toIso8601String(),
                'formatted_created_at' => $item->laboratoryPurchase?->formatted_created_at,
                'formatted_price' => $item->formatted_price,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, customer_email: string|null, updated_at: string|null, items_count: int}>
     */
    private function recentMonitoringCarts(string $productId): array
    {
        if (! Schema::hasTable('carts') || ! Schema::hasTable('cart_items')) {
            return [];
        }

        return Cart::query()
            ->with(['user:id,email', 'items:id,cart_id'])
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->whereHas('items', fn ($query) => $query->where('product_id', $productId))
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (Cart $cart) => [
                'id' => $cart->id,
                'customer_email' => $cart->user?->email,
                'updated_at' => $cart->updated_at?->toIso8601String(),
                'items_count' => $cart->items->count(),
            ])
            ->all();
    }
}

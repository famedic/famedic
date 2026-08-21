<?php

namespace App\Exports\Carts\Concerns;

use App\Models\Cart;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait BuildsCartExportQuery
{
    private function startDate(): ?Carbon
    {
        return ! empty($this->filters['start_date'])
            ? Carbon::parse($this->filters['start_date'], 'America/Monterrey')->startOfDay()->utc()
            : null;
    }

    private function endDate(): ?Carbon
    {
        return ! empty($this->filters['end_date'])
            ? Carbon::parse($this->filters['end_date'], 'America/Monterrey')->endOfDay()->utc()
            : null;
    }

    private function baseCartQuery(): Builder
    {
        return Cart::query()
            ->addSelect('carts.*')
            ->addSelect([
                'previous_laboratory_purchases_count' => DB::table('laboratory_purchases')
                    ->join('customers as purchase_customers', 'purchase_customers.id', '=', 'laboratory_purchases.customer_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('purchase_customers.user_id', 'carts.user_id')
                    ->whereNull('laboratory_purchases.deleted_at')
                    ->whereColumn('laboratory_purchases.created_at', '<', 'carts.created_at'),
                'previous_online_pharmacy_purchases_count' => DB::table('online_pharmacy_purchases')
                    ->join('customers as pharmacy_purchase_customers', 'pharmacy_purchase_customers.id', '=', 'online_pharmacy_purchases.customer_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('pharmacy_purchase_customers.user_id', 'carts.user_id')
                    ->whereNull('online_pharmacy_purchases.deleted_at')
                    ->whereColumn('online_pharmacy_purchases.created_at', '<', 'carts.created_at'),
            ])
            ->with([
                'events' => fn ($query) => $query
                    ->select('id', 'cart_id', 'metadata', 'occurred_at')
                    ->orderBy('occurred_at')
                    ->orderBy('id'),
                'items.laboratoryTest',
                'laboratoryAppointments.laboratoryStore',
                'laboratoryPurchases.transactions',
                'user.customer.laboratoryCartItems.laboratoryTest',
                'user.customer.laboratoryAppointments.laboratoryStore',
                'user.customer.laboratoryCheckoutDrafts.contact',
                'user.customer.laboratoryCheckoutDrafts.address',
                'user.customer.laboratoryPurchases.transactions',
            ])
            ->withCount('items')
            ->adminMonitoringFilter($this->filters, $this->startDate(), $this->endDate())
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }
}

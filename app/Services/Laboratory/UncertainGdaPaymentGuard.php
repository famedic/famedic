<?php

namespace App\Services\Laboratory;

use App\Enums\GdaOrderStatus;
use App\Enums\MonitoringCartStatus;
use App\Exceptions\LaboratoryPaymentAlreadyReceivedException;
use App\Models\Cart;
use App\Models\LaboratoryPurchase;
use Closure;
use Illuminate\Support\Facades\Cache;

class UncertainGdaPaymentGuard
{
    /**
     * @template TReturn
     * @param  Closure(Cart|null): TReturn  $callback
     * @return TReturn
     */
    public function withCartLock(?Cart $cart, Closure $callback): mixed
    {
        if (! $cart) {
            return $callback(null);
        }

        return Cache::lock("laboratory-cart-payment:{$cart->id}", 120)
            ->block(10, function () use ($cart, $callback) {
                $lockedCart = Cart::query()->whereKey($cart->id)->first();

                $this->ensureNoCapturedUncertainPurchase($lockedCart);

                return $callback($lockedCart);
            });
    }

    public function ensureNoCapturedUncertainPurchase(?Cart $cart): void
    {
        $purchase = $this->capturedUncertainPurchaseForCart($cart);

        if ($purchase instanceof LaboratoryPurchase) {
            throw new LaboratoryPaymentAlreadyReceivedException($purchase);
        }
    }

    public function capturedUncertainPurchaseForCart(?Cart $cart): ?LaboratoryPurchase
    {
        if (! $cart) {
            return null;
        }

        return LaboratoryPurchase::query()
            ->where('cart_id', $cart->id)
            ->where('gda_status', GdaOrderStatus::Uncertain->value)
            ->whereHas('transactions', fn ($query) => $this->successfulTransactionScope($query))
            ->with(['transactions' => fn ($query) => $this->successfulTransactionScope($query)])
            ->latest('id')
            ->first();
    }

    public function successfulTransactionScope($query)
    {
        return $query->where(function ($status) {
            $status
                ->whereIn('payment_status', [
                    'approved',
                    'captured',
                    'completed',
                    'paid',
                    'succeeded',
                ])
                ->orWhereIn('gateway_status', [
                    'APPROVED',
                    'CAPTURED',
                    'COMPLETED',
                    'approved',
                    'captured',
                    'completed',
                ])
                ->orWhereNotNull('gateway_transaction_id')
                ->orWhereNotNull('provider_transaction_id');
        });
    }
}

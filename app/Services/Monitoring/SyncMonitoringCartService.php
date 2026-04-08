<?php

namespace App\Services\Monitoring;

use App\Actions\OnlinePharmacy\FetchProductAction;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\OnlinePharmacyCartItem;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncMonitoringCartService
{
    public function __construct(
        private FetchProductAction $fetchProductAction,
    ) {
    }

    public function syncLaboratory(Customer $customer): void
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $items = $customer->laboratoryCartItems()->with('laboratoryTest')->get();

        if ($items->isEmpty()) {
            $this->deleteActiveCartIfEmpty($userId, MonitoringCartType::Lab);

            return;
        }

        DB::transaction(function () use ($userId, $items) {
            $cart = $this->firstOrCreateActiveCart($userId, MonitoringCartType::Lab);
            $cart->items()->delete();

            $total = 0;
            foreach ($items as $row) {
                /** @var LaboratoryCartItem $row */
                $test = $row->laboratoryTest;
                $name = $test?->name ?? 'Estudio de laboratorio';
                $line = numberCents($test?->famedic_price_cents ?? 0);
                $total += $line;

                $cart->items()->create([
                    'product_id' => $test ? (string) $test->id : (string) $row->laboratory_test_id,
                    'name' => $name,
                    'price' => $line,
                    'quantity' => 1,
                ]);
            }

            $cart->update([
                'total' => round($total, 2),
                'status' => MonitoringCartStatus::Active,
            ]);
        });
    }

    public function syncPharmacy(Customer $customer): void
    {
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $items = $customer->onlinePharmacyCartItems()->get();

        if ($items->isEmpty()) {
            $this->deleteActiveCartIfEmpty($userId, MonitoringCartType::Pharmacy);

            return;
        }

        DB::transaction(function () use ($userId, $items) {
            $cart = $this->firstOrCreateActiveCart($userId, MonitoringCartType::Pharmacy);
            $cart->items()->delete();

            $total = 0;
            foreach ($items as $row) {
                /** @var OnlinePharmacyCartItem $row */
                $name = 'Producto #' . $row->vitau_product_id;
                $unit = 0.0;
                try {
                    $product = ($this->fetchProductAction)((string) $row->vitau_product_id);
                    $name = $product['name'] ?? $name;
                    $unit = isset($product['price']) ? (float) $product['price'] : 0.0;
                } catch (Throwable) {
                }

                $qty = max(1, (int) $row->quantity);
                $line = round($unit * $qty, 2);
                $total += $line;

                $cart->items()->create([
                    'product_id' => (string) $row->vitau_product_id,
                    'name' => $name,
                    'price' => $unit,
                    'quantity' => $qty,
                ]);
            }

            $cart->update([
                'total' => round($total, 2),
                'status' => MonitoringCartStatus::Active,
            ]);
        });
    }

    public function markLaboratoryCartCompleted(Customer $customer): void
    {
        $this->syncLaboratory($customer);
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $cart = Cart::query()
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->first();

        if ($cart && $cart->items()->exists()) {
            $cart->update([
                'status' => MonitoringCartStatus::Completed,
                'completed_at' => now(),
            ]);
        }
    }

    public function markPharmacyCartCompleted(Customer $customer): void
    {
        $this->syncPharmacy($customer);
        $userId = $customer->user_id;
        if (! $userId) {
            return;
        }

        $cart = Cart::query()
            ->where('user_id', $userId)
            ->where('type', MonitoringCartType::Pharmacy)
            ->where('status', MonitoringCartStatus::Active)
            ->first();

        if ($cart && $cart->items()->exists()) {
            $cart->update([
                'status' => MonitoringCartStatus::Completed,
                'completed_at' => now(),
            ]);
        }
    }

    private function firstOrCreateActiveCart(int $userId, MonitoringCartType $type): Cart
    {
        $existing = Cart::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Cart::create([
            'user_id' => $userId,
            'type' => $type,
            'status' => MonitoringCartStatus::Active,
            'total' => 0,
        ]);
    }

    private function deleteActiveCartIfEmpty(int $userId, MonitoringCartType $type): void
    {
        Cart::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->delete();
    }
}

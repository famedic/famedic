<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutResumeLink;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GenerateLaboratoryCheckoutResumeLinkAction
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {}

    public function forCart(Cart $cart, ?LaboratoryBrand $brand = null): string
    {
        $cart->loadMissing('user.customer', 'items');

        if ($cart->type !== MonitoringCartType::Lab) {
            throw new RuntimeException('Solo se pueden generar links de reanudacion para carritos de laboratorio.');
        }

        $customer = $cart->user?->customer;
        if (! $customer) {
            throw new RuntimeException('El carrito no tiene un paciente asociado.');
        }

        $brand ??= $this->singleBrandForCart($cart);
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(max(1, (int) config('laboratory-checkout.resume_link_ttl_hours', 72)));

        DB::transaction(function () use ($cart, $customer, $brand, $plainToken, $expiresAt) {
            LaboratoryCheckoutResumeLink::query()->updateOrCreate(
                ['cart_id' => $cart->id],
                [
                    'customer_id' => $customer->id,
                    'laboratory_brand' => $brand,
                    'token_hash' => hash('sha256', $plainToken),
                    'expires_at' => $expiresAt,
                    'last_used_at' => null,
                    'revoked_at' => null,
                ],
            );
        });

        Log::info('Laboratory checkout resume link generated', [
            'cart_id' => $cart->id,
            'customer_id' => $customer->id,
            'laboratory_brand' => $brand->value,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return route('laboratory.checkout.resume', ['token' => $plainToken]);
    }

    public function forCustomerBrand(Customer $customer, LaboratoryBrand $brand): string
    {
        $this->syncMonitoringCartService->syncLaboratory($customer);

        $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer->fresh(), $brand);
        if (! $cart || $cart->status !== MonitoringCartStatus::Active) {
            throw new RuntimeException('No hay un carrito activo para generar el link de reanudacion.');
        }

        return $this->forCart($cart, $brand);
    }

    private function singleBrandForCart(Cart $cart): LaboratoryBrand
    {
        $brands = collect($cart->labBrands())
            ->pluck('value')
            ->filter()
            ->unique()
            ->values();

        if ($brands->count() !== 1) {
            throw new RuntimeException('El carrito debe pertenecer a una sola marca de laboratorio.');
        }

        return LaboratoryBrand::from($brands->first());
    }
}

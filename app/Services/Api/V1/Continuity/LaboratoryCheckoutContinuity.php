<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Continuity;

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\AkubicaCheckoutLink;
use App\Models\Cart;
use App\Models\Customer;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** RC2 Batch 2 · A4/D-2: Leo -> FAMEDIC laboratory checkout continuity. */
final class LaboratoryCheckoutContinuity
{
    public const PURPOSE = 'akubica_laboratory_continuity';

    public function __construct(private readonly SyncMonitoringCartService $syncMonitoringCartService) {}

    public static function enabled(): bool
    {
        return config('akubica_connectors.continuity.laboratory.enabled') === true
            && config('akubica_connectors.continuity.laboratory.certified') === true;
    }

    public static function ttlMinutes(): int
    {
        return min(10, max(1, (int) config('akubica_connectors.continuity.ttl_minutes', 10)));
    }

    public function issue(Request $request, Customer $customer, LaboratoryBrand $brand): array
    {
        $this->syncMonitoringCartService->syncLaboratory($customer);
        $cart = $this->activeCart($customer);
        if (! $cart || ! $this->hasBrandItems($customer, $brand)) {
            throw new LaboratoryContinuityUnavailable('CONTINUITY_UNAVAILABLE');
        }

        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(self::ttlMinutes());
        $url = route('akubica.checkout.continue', ['token' => $plainToken]);
        $allowedHost = config('akubica_connectors.continuity.allowed_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($allowedHost) || parse_url($url, PHP_URL_HOST) !== $allowedHost) {
            throw new LaboratoryContinuityUnavailable('CONTINUITY_UNAVAILABLE');
        }

        $link = DB::transaction(function () use ($request, $customer, $brand, $cart, $plainToken, $expiresAt) {
            AkubicaCheckoutLink::query()
                ->where('customer_id', $customer->id)
                ->where('laboratory_brand', $brand->value)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->where('metadata->purpose', self::PURPOSE)
                ->update(['expires_at' => now(), 'updated_at' => now()]);

            return AkubicaCheckoutLink::query()->create([
                'customer_id' => $customer->id,
                'token_hash' => hash('sha256', $plainToken),
                'laboratory_brand' => $brand,
                'expires_at' => $expiresAt,
                'created_by_token_id' => $request->user()?->currentAccessToken()?->getKey(),
                'metadata' => [
                    'purpose' => self::PURPOSE,
                    'service_line' => 'laboratory',
                    'laboratory_cart_id' => (int) $cart->id,
                    'cart_fingerprint' => $this->fingerprint($customer, $brand, (int) $cart->id),
                ],
            ]);
        });

        Log::info('akubica_laboratory_continuity_issued', [
            'link_id' => $link->id,
            'customer_id' => $customer->id,
            'laboratory_cart_id' => (int) $cart->id,
            'laboratory_brand' => $brand->value,
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ]);

        return [
            'continuity_url' => $url,
            'continuity_expires_at' => $expiresAt->utc()->toIso8601String(),
        ];
    }

    public function bindingFailure(AkubicaCheckoutLink $link): ?string
    {
        if (($link->metadata['purpose'] ?? null) !== self::PURPOSE
            || ($link->metadata['service_line'] ?? null) !== 'laboratory') {
            return 'La liga de checkout no es válida.';
        }

        $customer = $link->customer;
        $brand = $link->laboratory_brand;
        $cartId = $link->metadata['laboratory_cart_id'] ?? null;
        $cart = $customer && is_numeric($cartId) ? $this->activeCart($customer) : null;

        if (! $customer || ! $cart || (int) $cart->id !== (int) $cartId || ! $brand || ! $this->hasBrandItems($customer, $brand)) {
            return 'El carrito de laboratorio ya no está disponible.';
        }
        if (! hash_equals((string) ($link->metadata['cart_fingerprint'] ?? ''), $this->fingerprint($customer, $brand, (int) $cart->id))) {
            return 'El carrito de laboratorio cambió; solicita una nueva liga desde el asistente.';
        }

        return null;
    }

    private function activeCart(Customer $customer): ?Cart
    {
        return $customer->user_id
            ? Cart::query()->where('user_id', $customer->user_id)->where('type', MonitoringCartType::Lab)->where('status', MonitoringCartStatus::Active)->first()
            : null;
    }

    private function hasBrandItems(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $customer->laboratoryCartItems()->ofBrand($brand)->exists();
    }

    private function fingerprint(Customer $customer, LaboratoryBrand $brand, int $cartId): string
    {
        $items = $customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->orderBy('id')->get()->map(fn ($item) => [
            (int) $item->id,
            (int) $item->laboratory_test_id,
            (int) ($item->laboratoryTest?->famedic_price_cents ?? 0),
        ])->all();

        return hash('sha256', json_encode([$cartId, 'laboratory', $brand->value, $items], JSON_THROW_ON_ERROR));
    }
}

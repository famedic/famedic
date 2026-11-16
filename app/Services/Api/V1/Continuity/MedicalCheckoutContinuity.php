<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Continuity;

use App\Models\AkubicaCheckoutLink;
use App\Models\MedicalServiceCart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RC2 B24 (D2): Leo -> FAMEDIC medical (membership) checkout continuity.
 *
 * Reuses akubica_checkout_links and the /checkout/continue web route. Independent from the
 * benefits API. The link only opens the FAMEDIC membership checkout for the bound user and
 * cart; it never pays, never creates payment links and never activates memberships.
 * Disabled by default; enabling requires an explicit config change authorized by Security.
 */
final class MedicalCheckoutContinuity
{
    public const PURPOSE = 'akubica_medical_continuity';

    public static function enabled(): bool
    {
        return config('akubica_connectors.continuity.medical_services.enabled') === true
            && config('akubica_connectors.continuity.medical_services.certified') === true;
    }

    public static function ttlMinutes(): int
    {
        return min(10, max(1, (int) config('akubica_connectors.continuity.ttl_minutes', 10)));
    }

    public static function fingerprint(MedicalServiceCart $cart): string
    {
        $items = $cart->items()->orderBy('id')->get()
            ->map(fn ($item) => [
                (int) $item->id,
                (string) $item->item_type,
                (string) $item->membership_plan_code,
                (int) $item->quantity,
                (int) $item->unit_price_cents,
            ])->all();

        return hash('sha256', json_encode([(int) $cart->id, $items, $cart->benefits()->count()], JSON_THROW_ON_ERROR));
    }

    /**
     * Issues a single-use continuity link bound to the customer and the active medical cart.
     * Any previous unused medical continuity link of the same customer is expired (only the latest is valid).
     *
     * @return array{continuity_url: string, continuity_expires_at: string}
     *
     * @throws MedicalContinuityUnavailable
     */
    public function issue(Request $request, MedicalServiceCart $cart): array
    {
        $ttl = self::ttlMinutes();
        $plainToken = bin2hex(random_bytes(32));
        $url = route('akubica.checkout.continue', ['token' => $plainToken]);
        $allowedHost = config('akubica_connectors.continuity.allowed_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($allowedHost) || $allowedHost === ''
            || parse_url($url, PHP_URL_HOST) !== $allowedHost) {
            throw new MedicalContinuityUnavailable('CONTINUITY_UNAVAILABLE');
        }

        $customerId = (int) $cart->customer_id;
        $expiresAt = now()->addMinutes($ttl);

        $link = DB::transaction(function () use ($request, $cart, $customerId, $plainToken, $expiresAt, $ttl) {
            AkubicaCheckoutLink::query()
                ->where('customer_id', $customerId)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->where('metadata->purpose', self::PURPOSE)
                ->update(['expires_at' => now(), 'updated_at' => now()]);

            return AkubicaCheckoutLink::query()->create([
                'customer_id' => $customerId,
                'token_hash' => hash('sha256', $plainToken),
                'laboratory_brand' => null,
                'expires_at' => $expiresAt,
                'created_by_token_id' => $request->user()?->currentAccessToken()?->getKey(),
                'metadata' => [
                    'purpose' => self::PURPOSE,
                    'ttl_minutes' => $ttl,
                    'medical_service_cart_id' => (int) $cart->id,
                    'cart_fingerprint' => self::fingerprint($cart),
                ],
            ]);
        });

        // Never log the plain token, the URL or personal data.
        Log::info('akubica_medical_continuity_issued', [
            'link_id' => $link->id,
            'customer_id' => $customerId,
            'medical_service_cart_id' => (int) $cart->id,
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ]);

        return [
            'continuity_url' => $url,
            'continuity_expires_at' => $expiresAt->utc()->toIso8601String(),
        ];
    }

    /**
     * Returns a user-facing failure message when the link no longer matches its bound cart, or null when valid.
     */
    public function bindingFailure(AkubicaCheckoutLink $link): ?string
    {
        $customer = $link->customer;

        if ($customer === null) {
            return 'La liga de checkout no es válida.';
        }

        if ($customer->medical_attention_subscription_is_active) {
            return 'Ya cuentas con una membresía activa.';
        }

        $cartId = $link->metadata['medical_service_cart_id'] ?? null;
        $cart = is_numeric($cartId)
            ? MedicalServiceCart::query()->whereKey((int) $cartId)->where('customer_id', $customer->id)->active()->first()
            : null;

        if ($cart === null) {
            return 'El carrito ya no está disponible.';
        }

        if (! $cart->items()->exists()) {
            return 'El carrito ya no tiene servicios.';
        }

        if (! hash_equals((string) ($link->metadata['cart_fingerprint'] ?? ''), self::fingerprint($cart))) {
            return 'El carrito cambió; solicita una nueva liga desde el asistente.';
        }

        return null;
    }
}

<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Models\Cart;
use App\Models\CartEvent;
use Carbon\CarbonInterface;

/**
 * Última actividad real del usuario en un carrito, derivada de cart_events.
 *
 * No usa carts.updated_at porque procesos internos (sync de snapshot, etc.)
 * pueden actualizarlo sin interacción del usuario.
 */
class CartUserActivityResolver
{
    /**
     * Eventos que representan acción explícita del usuario en checkout/carrito.
     *
     * @var list<CartEventType>
     */
    private const USER_ACTIVITY_EVENTS = [
        CartEventType::CheckoutVisited,
        CartEventType::CheckoutStarted,
        CartEventType::PatientSelected,
        CartEventType::AddressSelected,
        CartEventType::PaymentMethodSelected,
        CartEventType::AppointmentRequested,
        CartEventType::CartItemAdded,
        CartEventType::CartItemRemoved,
        CartEventType::CartItemQuantityChanged,
        CartEventType::CartResumed,
        CartEventType::PaymentStarted,
    ];

    public function __construct(
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * @return list<string>
     */
    public static function userActivityEventValues(): array
    {
        return array_map(
            fn (CartEventType $type) => $type->value,
            self::USER_ACTIVITY_EVENTS,
        );
    }

    /**
     * Expresión SQL de última actividad real del usuario (cart_events), con fallback a updated_at.
     */
    public static function lastActivityAtSql(string $cartAlias = 'carts'): string
    {
        return self::lastUserActivityAtSql($cartAlias, allowUpdatedAtFallback: true);
    }

    /**
     * @param  bool  $allowUpdatedAtFallback  Si es false y existe cart_events, solo eventos reales del usuario.
     */
    public static function lastUserActivityAtSql(string $cartAlias = 'carts', bool $allowUpdatedAtFallback = true): string
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('cart_events')) {
            return $allowUpdatedAtFallback ? "{$cartAlias}.updated_at" : 'NULL';
        }

        $events = collect(self::userActivityEventValues())
            ->map(fn (string $event) => "'".str_replace("'", "''", $event)."'")
            ->join(', ');

        $eventsOnly = "(
            SELECT MAX(cart_events.occurred_at)
            FROM cart_events
            WHERE cart_events.cart_id = {$cartAlias}.id
                AND cart_events.event IN ({$events})
        )";

        if ($allowUpdatedAtFallback) {
            return "COALESCE({$eventsOnly}, {$cartAlias}.updated_at)";
        }

        return $eventsOnly;
    }

    public function lastUserActivityAt(Cart $cart): CarbonInterface
    {
        $latest = $cart->events()
            ->whereIn(
                'event',
                array_map(fn (CartEventType $type) => $type->value, self::USER_ACTIVITY_EVENTS),
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($latest?->occurred_at instanceof CarbonInterface) {
            return $latest->occurred_at->copy();
        }

        return $cart->updated_at?->copy() ?? $cart->created_at?->copy() ?? now();
    }

    /**
     * Registra visita autenticada al checkout (GET completo, no polling).
     */
    public function recordCheckoutVisit(Cart $cart, ?string $brand = null, ?array $metadata = []): ?CartEvent
    {
        return $this->cartEventRecorder->record(
            $cart,
            CartEventType::CheckoutVisited,
            array_filter([
                'brand' => $brand,
                ...$metadata,
            ], fn ($value) => $value !== null && $value !== ''),
            now(),
            'laboratory_checkout_visit',
            sprintf('cart:%d:checkout_visited:%s', $cart->id, now()->format('Y-m-d-H-i')),
        );
    }

}

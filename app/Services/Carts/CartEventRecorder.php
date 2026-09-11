<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Models\Cart;
use App\Models\CartEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CartEventRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Cart $cart,
        CartEventType|string $event,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
        ?string $source = null,
        ?string $idempotencyKey = null,
    ): ?CartEvent {
        try {
            if (! $this->isAvailable()) {
                return null;
            }

            $eventValue = $event instanceof CartEventType ? $event->value : $event;
            $payload = [
                'source' => $source,
                'metadata' => $this->sanitizeMetadata($metadata),
                'occurred_at' => $occurredAt ?? now(),
            ];

            if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
                return CartEvent::query()->firstOrCreate(
                    [
                        'cart_id' => $cart->id,
                        'event' => $eventValue,
                        'idempotency_key' => mb_substr($idempotencyKey, 0, 160),
                    ],
                    $payload,
                );
            }

            return CartEvent::query()->create(array_merge($payload, [
                'cart_id' => $cart->id,
                'event' => $eventValue,
                'idempotency_key' => null,
            ]));
        } catch (Throwable $e) {
            Log::warning('[CartEvents] Failed to record cart event', [
                'cart_id' => $cart->id,
                'event' => $event instanceof CartEventType ? $event->value : $event,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordOnce(
        Cart $cart,
        CartEventType|string $event,
        string $idempotencyKey,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
        ?string $source = null,
    ): ?CartEvent {
        return $this->record($cart, $event, $metadata, $occurredAt, $source, $idempotencyKey);
    }

    private function isAvailable(): bool
    {
        return Schema::hasTable('cart_events') && Schema::hasColumn('cart_events', 'cart_id');
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->sanitizeMetadata($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
            }
        }

        return $safe;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = mb_strtolower($key);

        foreach (['token', 'card', 'raw', 'response', 'secret', 'password', 'pci', 'cvv', 'transaction_id'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Services\ActiveCampaign;

use App\Enums\ActiveCampaignSiteEvent;
use App\Models\Cart;
use App\Models\CartEvent;

class CartActiveCampaignSiteEventPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ActiveCampaignSiteEvent $siteEvent, Cart $cart, CartEvent $cartEvent): array
    {
        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];

        return match ($siteEvent) {
            ActiveCampaignSiteEvent::CartAbandoned => $this->buildAbandoned($cart, $metadata),
            ActiveCampaignSiteEvent::CartResumed => $this->buildResumed($cart, $metadata),
            ActiveCampaignSiteEvent::CartRecovered => $this->buildRecovered($cart, $metadata),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildAbandoned(Cart $cart, array $metadata): array
    {
        return [
            'cart_id' => (int) $cart->id,
            'episode' => (int) ($metadata['episode'] ?? 0),
            'cart_type' => $cart->type->value,
            'brand' => $metadata['brand'] ?? null,
            'cart_total' => (float) $cart->total,
            'items_count' => $cart->relationLoaded('items')
                ? $cart->items->count()
                : $cart->items()->count(),
            'last_activity_at' => $metadata['last_activity_at'] ?? null,
            'abandoned_at' => $metadata['abandoned_at'] ?? null,
            'minutes_inactive' => isset($metadata['minutes_inactive'])
                ? (int) $metadata['minutes_inactive']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildResumed(Cart $cart, array $metadata): array
    {
        return [
            'cart_id' => (int) $cart->id,
            'episode' => (int) ($metadata['episode'] ?? 0),
            'brand' => $metadata['brand'] ?? null,
            'resumed_at' => $metadata['resumed_at'] ?? null,
            'abandoned_at' => $metadata['abandoned_at'] ?? null,
            'abandoned_duration_minutes' => isset($metadata['abandoned_duration_minutes'])
                ? (int) $metadata['abandoned_duration_minutes']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildRecovered(Cart $cart, array $metadata): array
    {
        $payload = [
            'cart_id' => (int) $cart->id,
            'episodes_count' => isset($metadata['episodes_count'])
                ? (int) $metadata['episodes_count']
                : null,
            'last_episode' => isset($metadata['last_episode'])
                ? (int) $metadata['last_episode']
                : null,
            'recovered_at' => $metadata['recovered_at'] ?? null,
        ];

        if (isset($metadata['purchase_id'])) {
            $payload['purchase_id'] = (int) $metadata['purchase_id'];
        }

        return array_filter($payload, static fn ($value) => $value !== null);
    }
}

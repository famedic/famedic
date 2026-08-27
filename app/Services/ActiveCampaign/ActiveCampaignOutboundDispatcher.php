<?php

namespace App\Services\ActiveCampaign;

use App\Enums\ActiveCampaignSiteEvent;
use App\Enums\CartEventType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActiveCampaignOutboundDispatcher
{
    public function __construct(
        private ActiveCampaignDispatchService $dispatchService,
        private ActiveCampaignTagResolver $tagResolver,
        private CartActiveCampaignSiteEventPayloadBuilder $siteEventPayloadBuilder,
    ) {}

    public function isCartOutboxEnabled(): bool
    {
        return $this->dispatchService->isCartOutboxEnabled();
    }

    public function isCartSiteEventsEnabled(): bool
    {
        return $this->dispatchService->isCartSiteEventsEnabled();
    }

    public function isCartTagRemoveEnabled(): bool
    {
        return $this->dispatchService->isCartTagRemoveEnabled();
    }

    public function idempotencyKeyForCartAbandonedTag(int $cartId, int $episode): string
    {
        return "cart:{$cartId}:abandoned:episode:{$episode}:tag:add";
    }

    public function idempotencyKeyForCartResumedTagRemove(int $cartId, int $episode): string
    {
        return "cart:{$cartId}:resumed:episode:{$episode}:tag:remove";
    }

    public function idempotencyKeyForCartRecoveredTagRemove(int $cartId): string
    {
        return "cart:{$cartId}:recovered:tag:remove";
    }

    public function idempotencyKeyForCartAbandonedSiteEvent(int $cartId, int $episode): string
    {
        return "cart:{$cartId}:abandoned:episode:{$episode}:site_event";
    }

    public function idempotencyKeyForCartResumedSiteEvent(int $cartId, int $episode): string
    {
        return "cart:{$cartId}:resumed:episode:{$episode}:site_event";
    }

    public function idempotencyKeyForCartRecoveredSiteEvent(int $cartId): string
    {
        return "cart:{$cartId}:recovered:site_event";
    }

    /**
     * Punto de entrada único desde cart_events.
     *
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueFromCartEvent(Cart $cart, CartEvent $cartEvent): array
    {
        $eventValue = $cartEvent->event instanceof CartEventType
            ? $cartEvent->event->value
            : (string) $cartEvent->event;

        return match ($eventValue) {
            CartEventType::CartAbandoned->value => $this->enqueueAbandonedOutbox($cart, $cartEvent),
            CartEventType::CartResumed->value => $this->enqueueResumedOutbox($cart, $cartEvent),
            CartEventType::CartRecovered->value => $this->enqueueRecoveredOutbox($cart, $cartEvent),
            default => [],
        };
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueAbandonedOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];

        $tagDispatch = $this->enqueueAbandonedTagFromCartEvent($cart, $cartEvent);
        if ($tagDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $tagDispatch;
        }

        $siteDispatch = $this->enqueueAbandonedSiteEventFromCartEvent($cart, $cartEvent);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueResumedOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];

        $removeDispatch = $this->enqueueResumedTagRemoveFromCartEvent($cart, $cartEvent);
        if ($removeDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $removeDispatch;
        }

        $siteDispatch = $this->enqueueResumedSiteEventFromCartEvent($cart, $cartEvent);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    /**
     * Tag remove en recovered es idempotente en AC; se crea siempre para trazabilidad.
     *
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueRecoveredOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];

        $removeDispatch = $this->enqueueRecoveredTagRemoveFromCartEvent($cart, $cartEvent);
        if ($removeDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $removeDispatch;
        }

        $siteDispatch = $this->enqueueRecoveredSiteEventFromCartEvent($cart, $cartEvent);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    public function enqueueAbandonedTagFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartOutboxEnabled()) {
            return null;
        }

        if (! config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
            return null;
        }

        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $episode = (int) ($metadata['episode'] ?? 0);

        if ($episode <= 0) {
            return null;
        }

        return $this->dispatchTagAdd(
            cart: $cart,
            tagKey: 'cart.abandoned',
            eventType: CartEventType::CartAbandoned->value,
            idempotencyKey: $this->idempotencyKeyForCartAbandonedTag((int) $cart->id, $episode),
            payloadExtras: $this->baseCartEventPayloadExtras($cartEvent, $metadata, $episode),
        );
    }

    public function enqueueAbandonedSiteEventFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $episode = (int) ($metadata['episode'] ?? 0);

        if ($episode <= 0) {
            return null;
        }

        $siteEvent = ActiveCampaignSiteEvent::CartAbandoned;

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: $siteEvent,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::CartAbandoned->value,
            idempotencyKey: $this->idempotencyKeyForCartAbandonedSiteEvent((int) $cart->id, $episode),
            payloadExtras: $this->baseCartEventPayloadExtras($cartEvent, $metadata, $episode),
        );
    }

    public function enqueueResumedTagRemoveFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartTagRemoveEnabled()) {
            return null;
        }

        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $episode = (int) ($metadata['episode'] ?? 0);

        if ($episode <= 0) {
            return null;
        }

        return $this->dispatchTagRemove(
            cart: $cart,
            tagKey: 'cart.abandoned',
            eventType: CartEventType::CartResumed->value,
            idempotencyKey: $this->idempotencyKeyForCartResumedTagRemove((int) $cart->id, $episode),
            payloadExtras: $this->baseCartEventPayloadExtras($cartEvent, $metadata, $episode),
        );
    }

    public function enqueueResumedSiteEventFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $episode = (int) ($metadata['episode'] ?? 0);

        if ($episode <= 0) {
            return null;
        }

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: ActiveCampaignSiteEvent::CartResumed,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::CartResumed->value,
            idempotencyKey: $this->idempotencyKeyForCartResumedSiteEvent((int) $cart->id, $episode),
            payloadExtras: $this->baseCartEventPayloadExtras($cartEvent, $metadata, $episode),
        );
    }

    public function enqueueRecoveredTagRemoveFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartTagRemoveEnabled()) {
            return null;
        }

        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];

        return $this->dispatchTagRemove(
            cart: $cart,
            tagKey: 'cart.abandoned',
            eventType: CartEventType::CartRecovered->value,
            idempotencyKey: $this->idempotencyKeyForCartRecoveredTagRemove((int) $cart->id),
            payloadExtras: array_merge(
                $this->baseCartEventPayloadExtras($cartEvent, $metadata, null),
                [
                    'episodes_count' => $metadata['episodes_count'] ?? null,
                    'last_episode' => $metadata['last_episode'] ?? null,
                ],
            ),
        );
    }

    public function enqueueRecoveredSiteEventFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: ActiveCampaignSiteEvent::CartRecovered,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::CartRecovered->value,
            idempotencyKey: $this->idempotencyKeyForCartRecoveredSiteEvent((int) $cart->id),
            payloadExtras: $this->baseCartEventPayloadExtras($cartEvent, $metadata, null),
        );
    }

    /**
     * @param  array<string, mixed>  $payloadExtras
     */
    public function dispatchTagAdd(
        Cart $cart,
        string $tagKey,
        string $eventType,
        string $idempotencyKey,
        array $payloadExtras = [],
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartOutboxEnabled()) {
            return null;
        }

        $cart->loadMissing('user.customer');
        $user = $cart->user;
        $email = $this->resolveEligibleEmail($user);

        if ($email === null) {
            return $this->createSkippedCartDispatch(
                cart: $cart,
                eventType: $eventType,
                idempotencyKey: $idempotencyKey,
                reason: 'no_eligible_email',
                payloadExtras: $payloadExtras,
                operation: 'tag_add',
                tagKey: $tagKey,
            );
        }

        $tag = $this->tagResolver->resolve($tagKey);

        if ($tag['id'] === null && $tag['name'] === null) {
            return $this->createSkippedCartDispatch(
                cart: $cart,
                eventType: $eventType,
                idempotencyKey: $idempotencyKey,
                reason: 'tag_not_configured',
                payloadExtras: $payloadExtras,
                operation: 'tag_add',
                tagKey: $tagKey,
            );
        }

        try {
            $dispatch = $this->dispatchService->createOrSkipByIdempotencyKey([
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'entity_type' => 'cart',
                'entity_id' => $cart->id,
                'related_entity_type' => 'cart_event',
                'related_entity_id' => $payloadExtras['cart_event_id'] ?? null,
                'user_id' => $user?->id,
                'customer_id' => $user?->customer?->id,
                'email' => $email,
                'payload' => $this->buildCartTagPayload(
                    operation: 'tag_add',
                    tagKey: $tagKey,
                    tag: $tag,
                    cart: $cart,
                    email: $email,
                    extras: $payloadExtras,
                ),
            ]);

            $this->dispatchJobIfPending($dispatch);

            return $dispatch;
        } catch (Throwable $e) {
            Log::warning('AC Outbound: fallo al encolar tag_add de carrito', [
                'cart_id' => $cart->id,
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payloadExtras
     */
    public function dispatchTagRemove(
        Cart $cart,
        string $tagKey,
        string $eventType,
        string $idempotencyKey,
        array $payloadExtras = [],
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartTagRemoveEnabled()) {
            return null;
        }

        $cart->loadMissing('user.customer');
        $user = $cart->user;
        $email = $this->resolveEligibleEmail($user);

        if ($email === null) {
            return $this->createSkippedCartDispatch(
                cart: $cart,
                eventType: $eventType,
                idempotencyKey: $idempotencyKey,
                reason: 'no_eligible_email',
                payloadExtras: $payloadExtras,
                operation: 'tag_remove',
                tagKey: $tagKey,
            );
        }

        $tag = $this->tagResolver->resolve($tagKey);

        if ($tag['id'] === null && $tag['name'] === null) {
            return null;
        }

        $dispatch = $this->dispatchService->createOrSkipByIdempotencyKey([
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'entity_type' => 'cart',
            'entity_id' => $cart->id,
            'related_entity_type' => 'cart_event',
            'related_entity_id' => $payloadExtras['cart_event_id'] ?? null,
            'user_id' => $user?->id,
            'customer_id' => $user?->customer?->id,
            'email' => $email,
            'payload' => $this->buildCartTagPayload(
                operation: 'tag_remove',
                tagKey: $tagKey,
                tag: $tag,
                cart: $cart,
                email: $email,
                extras: $payloadExtras,
            ),
        ]);

        $this->dispatchJobIfPending($dispatch);

        return $dispatch;
    }

    /**
     * @param  array<string, mixed>  $payloadExtras
     */
    public function dispatchSiteEvent(
        Cart $cart,
        ActiveCampaignSiteEvent $siteEvent,
        CartEvent $cartEvent,
        string $sourceEventType,
        string $idempotencyKey,
        array $payloadExtras = [],
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        $cart->loadMissing(['user.customer', 'items']);
        $user = $cart->user;
        $email = $this->resolveEligibleEmail($user);

        if ($email === null) {
            return $this->createSkippedSiteEventDispatch(
                cart: $cart,
                siteEvent: $siteEvent,
                sourceEventType: $sourceEventType,
                idempotencyKey: $idempotencyKey,
                reason: 'no_eligible_email',
                payloadExtras: $payloadExtras,
            );
        }

        $eventName = $siteEvent->resolvedName();
        $eventData = $this->siteEventPayloadBuilder->build($siteEvent, $cart, $cartEvent);

        $dispatch = $this->dispatchService->createOrSkipByIdempotencyKey([
            'event_type' => $eventName,
            'idempotency_key' => $idempotencyKey,
            'entity_type' => 'cart',
            'entity_id' => $cart->id,
            'related_entity_type' => 'cart_event',
            'related_entity_id' => $payloadExtras['cart_event_id'] ?? $cartEvent->id,
            'user_id' => $user?->id,
            'customer_id' => $user?->customer?->id,
            'email' => $email,
            'payload' => array_merge([
                'operation' => 'site_event',
                'event_name' => $eventName,
                'source_event_type' => $sourceEventType,
                'cart_id' => $cart->id,
                'email' => $email,
                'event_data' => $eventData,
            ], $payloadExtras),
        ]);

        $this->dispatchJobIfPending($dispatch);

        return $dispatch;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function baseCartEventPayloadExtras(CartEvent $cartEvent, array $metadata, ?int $episode): array
    {
        $extras = [
            'cart_event_id' => $cartEvent->id,
            'source_cart_event' => $cartEvent->event instanceof CartEventType
                ? $cartEvent->event->value
                : (string) $cartEvent->event,
        ];

        if ($episode !== null) {
            $extras['episode'] = $episode;
        }

        return $extras;
    }

    private function dispatchJobIfPending(ActiveCampaignDispatch $dispatch): void
    {
        if ($dispatch->wasRecentlyCreated && $dispatch->status === ActiveCampaignDispatch::STATUS_PENDING) {
            DispatchActiveCampaignOutboundJob::dispatch($dispatch->id);
        }
    }

    private function resolveEligibleEmail(?User $user): ?string
    {
        $email = is_string($user?->email) ? trim($user->email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $payloadExtras
     */
    private function createSkippedCartDispatch(
        Cart $cart,
        string $eventType,
        string $idempotencyKey,
        string $reason,
        array $payloadExtras,
        string $operation,
        string $tagKey,
    ): ActiveCampaignDispatch {
        $cart->loadMissing('user.customer');

        $dispatch = $this->dispatchService->createOrSkipByIdempotencyKey([
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'entity_type' => 'cart',
            'entity_id' => $cart->id,
            'related_entity_type' => 'cart_event',
            'related_entity_id' => $payloadExtras['cart_event_id'] ?? null,
            'user_id' => $cart->user?->id,
            'customer_id' => $cart->user?->customer?->id,
            'email' => $cart->user?->email,
            'payload' => array_merge([
                'operation' => $operation,
                'tag_key' => $tagKey,
                'cart_id' => $cart->id,
                'skip_reason' => $reason,
            ], $payloadExtras),
        ]);

        if ($dispatch->wasRecentlyCreated) {
            $this->dispatchService->markSkipped($dispatch, $reason);
        }

        return $dispatch->fresh();
    }

    /**
     * @param  array<string, mixed>  $payloadExtras
     */
    private function createSkippedSiteEventDispatch(
        Cart $cart,
        ActiveCampaignSiteEvent $siteEvent,
        string $sourceEventType,
        string $idempotencyKey,
        string $reason,
        array $payloadExtras,
    ): ActiveCampaignDispatch {
        $cart->loadMissing('user.customer');
        $eventName = $siteEvent->resolvedName();

        $dispatch = $this->dispatchService->createOrSkipByIdempotencyKey([
            'event_type' => $eventName,
            'idempotency_key' => $idempotencyKey,
            'entity_type' => 'cart',
            'entity_id' => $cart->id,
            'related_entity_type' => 'cart_event',
            'related_entity_id' => $payloadExtras['cart_event_id'] ?? null,
            'user_id' => $cart->user?->id,
            'customer_id' => $cart->user?->customer?->id,
            'email' => $cart->user?->email,
            'payload' => array_merge([
                'operation' => 'site_event',
                'event_name' => $eventName,
                'source_event_type' => $sourceEventType,
                'cart_id' => $cart->id,
                'skip_reason' => $reason,
            ], $payloadExtras),
        ]);

        if ($dispatch->wasRecentlyCreated) {
            $this->dispatchService->markSkipped($dispatch, $reason);
        }

        return $dispatch->fresh();
    }

    /**
     * @param  array{id: int|null, name: string|null, key: string}  $tag
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function buildCartTagPayload(
        string $operation,
        string $tagKey,
        array $tag,
        Cart $cart,
        string $email,
        array $extras = [],
    ): array {
        $user = $cart->user;

        return array_merge([
            'operation' => $operation,
            'tag_key' => $tagKey,
            'tag_id' => $tag['id'],
            'tag_name' => $tag['name'],
            'cart_id' => $cart->id,
            'email' => $email,
            'user_id' => $user?->id,
            'customer_id' => $user?->customer?->id,
            'contact' => $user instanceof User ? [
                'email' => $user->email,
                'first_name' => $user->name,
                'paternal_lastname' => $user->paternal_lastname,
                'maternal_lastname' => $user->maternal_lastname,
                'phone' => $user->phone,
                'gender' => $user->gender == 1 ? 'Masculino' : 'Femenino',
                'birth_date' => optional($user->birth_date)?->format('Y-m-d'),
                'phone_country' => $user->phone_country,
                'state' => $user->state,
            ] : null,
        ], $extras);
    }
}

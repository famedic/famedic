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

    public function isCartAppointmentSignalsEnabled(): bool
    {
        return $this->dispatchService->isCartAppointmentSignalsEnabled();
    }

    public function isCartCallSignalsEnabled(): bool
    {
        return $this->dispatchService->isCartCallSignalsEnabled();
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

    public function idempotencyKeyForAppointmentPendingTag(int $appointmentId): string
    {
        return "appointment:{$appointmentId}:pending_5m:tag:add";
    }

    public function idempotencyKeyForAppointmentPendingSiteEvent(int $appointmentId): string
    {
        return "appointment:{$appointmentId}:pending_5m:site_event";
    }

    public function idempotencyKeyForAppointmentPendingTagRemove(int $appointmentId): string
    {
        return "appointment:{$appointmentId}:pending_5m:tag:remove";
    }

    public function idempotencyKeyForAppointmentConfirmedSiteEvent(int $appointmentId): string
    {
        return "appointment:{$appointmentId}:confirmed:site_event";
    }

    public function idempotencyKeyForCallRequestedTag(int $interactionId): string
    {
        return "appointment_interaction:{$interactionId}:call_requested:tag:add";
    }

    public function idempotencyKeyForCallRequestedSiteEvent(int $interactionId): string
    {
        return "appointment_interaction:{$interactionId}:call_requested:site_event";
    }

    public function idempotencyKeyForCallAttemptedTag(int $interactionId): string
    {
        return "appointment_interaction:{$interactionId}:call_attempted:tag:add";
    }

    public function idempotencyKeyForCallAttemptedSiteEvent(int $interactionId): string
    {
        return "appointment_interaction:{$interactionId}:call_attempted:site_event";
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
            CartEventType::AppointmentPending5m->value => $this->enqueueAppointmentPendingOutbox($cart, $cartEvent),
            CartEventType::AppointmentConfirmed->value => $this->enqueueAppointmentConfirmedOutbox($cart, $cartEvent),
            CartEventType::CallRequested->value => $this->enqueueCallRequestedOutbox($cart, $cartEvent),
            CartEventType::CallAttempted->value => $this->enqueueCallAttemptedOutbox($cart, $cartEvent),
            CartEventType::CartCompleted->value => $this->enqueueCartCompletedOutbox($cart, $cartEvent),
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

        foreach ($this->enqueueAppointmentPendingTagRemovesForCart(
            $cart,
            CartEventType::CartRecovered->value,
        ) as $removeDispatch) {
            $dispatches[] = $removeDispatch;
        }

        return $dispatches;
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueAppointmentPendingOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];

        $tagDispatch = $this->enqueueAppointmentPendingTagFromCartEvent($cart, $cartEvent);
        if ($tagDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $tagDispatch;
        }

        $siteDispatch = $this->enqueueAppointmentPendingSiteEventFromCartEvent($cart, $cartEvent);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueAppointmentConfirmedOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];
        $appointmentId = $this->resolveAppointmentIdFromCartEvent($cartEvent);

        if ($appointmentId === null) {
            return $dispatches;
        }

        $removeDispatch = $this->enqueueAppointmentPendingTagRemove($cart, $cartEvent, $appointmentId);
        if ($removeDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $removeDispatch;
        }

        $siteDispatch = $this->enqueueAppointmentConfirmedSiteEventFromCartEvent($cart, $cartEvent, $appointmentId);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueCallRequestedOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];
        $interactionId = $this->resolveInteractionIdFromCartEvent($cartEvent);

        if ($interactionId === null) {
            return $dispatches;
        }

        $tagDispatch = $this->enqueueCallRequestedTagFromCartEvent($cart, $cartEvent, $interactionId);
        if ($tagDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $tagDispatch;
        }

        $siteDispatch = $this->enqueueCallRequestedSiteEventFromCartEvent($cart, $cartEvent, $interactionId);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueCallAttemptedOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        $dispatches = [];
        $interactionId = $this->resolveInteractionIdFromCartEvent($cartEvent);

        if ($interactionId === null) {
            return $dispatches;
        }

        $tagDispatch = $this->enqueueCallAttemptedTagFromCartEvent($cart, $cartEvent, $interactionId);
        if ($tagDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $tagDispatch;
        }

        $siteDispatch = $this->enqueueCallAttemptedSiteEventFromCartEvent($cart, $cartEvent, $interactionId);
        if ($siteDispatch instanceof ActiveCampaignDispatch) {
            $dispatches[] = $siteDispatch;
        }

        return $dispatches;
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    public function enqueueCartCompletedOutbox(Cart $cart, CartEvent $cartEvent): array
    {
        return $this->enqueueAppointmentPendingTagRemovesForCart(
            $cart,
            CartEventType::CartCompleted->value,
        );
    }

    public function enqueueAppointmentPendingTagFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartAppointmentSignalsEnabled()) {
            return null;
        }

        $appointmentId = $this->resolveAppointmentIdFromCartEvent($cartEvent);
        if ($appointmentId === null) {
            return null;
        }

        return $this->dispatchTagAdd(
            cart: $cart,
            tagKey: 'cart.appointment_pending',
            eventType: CartEventType::AppointmentPending5m->value,
            idempotencyKey: $this->idempotencyKeyForAppointmentPendingTag($appointmentId),
            payloadExtras: $this->appointmentSignalPayloadExtras($cartEvent),
        );
    }

    public function enqueueAppointmentPendingSiteEventFromCartEvent(Cart $cart, CartEvent $cartEvent): ?ActiveCampaignDispatch
    {
        if (! $this->isCartAppointmentSignalsEnabled() || ! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        $appointmentId = $this->resolveAppointmentIdFromCartEvent($cartEvent);
        if ($appointmentId === null) {
            return null;
        }

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: ActiveCampaignSiteEvent::AppointmentPending5m,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::AppointmentPending5m->value,
            idempotencyKey: $this->idempotencyKeyForAppointmentPendingSiteEvent($appointmentId),
            payloadExtras: $this->appointmentSignalPayloadExtras($cartEvent),
        );
    }

    public function enqueueAppointmentPendingTagRemove(
        Cart $cart,
        CartEvent $cartEvent,
        int $appointmentId,
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartAppointmentSignalsEnabled() || ! $this->isCartTagRemoveEnabled()) {
            return null;
        }

        return $this->dispatchTagRemove(
            cart: $cart,
            tagKey: 'cart.appointment_pending',
            eventType: CartEventType::AppointmentConfirmed->value,
            idempotencyKey: $this->idempotencyKeyForAppointmentPendingTagRemove($appointmentId),
            payloadExtras: $this->appointmentSignalPayloadExtras($cartEvent, $appointmentId),
        );
    }

    public function enqueueAppointmentConfirmedSiteEventFromCartEvent(
        Cart $cart,
        CartEvent $cartEvent,
        int $appointmentId,
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartAppointmentSignalsEnabled() || ! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: ActiveCampaignSiteEvent::AppointmentConfirmed,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::AppointmentConfirmed->value,
            idempotencyKey: $this->idempotencyKeyForAppointmentConfirmedSiteEvent($appointmentId),
            payloadExtras: $this->appointmentSignalPayloadExtras($cartEvent, $appointmentId),
        );
    }

    public function enqueueCallRequestedTagFromCartEvent(
        Cart $cart,
        CartEvent $cartEvent,
        int $interactionId,
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartCallSignalsEnabled()) {
            return null;
        }

        return $this->dispatchTagAdd(
            cart: $cart,
            tagKey: 'call.requested',
            eventType: CartEventType::CallRequested->value,
            idempotencyKey: $this->idempotencyKeyForCallRequestedTag($interactionId),
            payloadExtras: $this->callSignalPayloadExtras($cartEvent, $interactionId),
        );
    }

    public function enqueueCallRequestedSiteEventFromCartEvent(
        Cart $cart,
        CartEvent $cartEvent,
        int $interactionId,
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartCallSignalsEnabled() || ! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: ActiveCampaignSiteEvent::CallRequested,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::CallRequested->value,
            idempotencyKey: $this->idempotencyKeyForCallRequestedSiteEvent($interactionId),
            payloadExtras: $this->callSignalPayloadExtras($cartEvent, $interactionId),
        );
    }

    public function enqueueCallAttemptedTagFromCartEvent(
        Cart $cart,
        CartEvent $cartEvent,
        int $interactionId,
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartCallSignalsEnabled()) {
            return null;
        }

        return $this->dispatchTagAdd(
            cart: $cart,
            tagKey: 'call.attempted',
            eventType: CartEventType::CallAttempted->value,
            idempotencyKey: $this->idempotencyKeyForCallAttemptedTag($interactionId),
            payloadExtras: $this->callSignalPayloadExtras($cartEvent, $interactionId),
        );
    }

    public function enqueueCallAttemptedSiteEventFromCartEvent(
        Cart $cart,
        CartEvent $cartEvent,
        int $interactionId,
    ): ?ActiveCampaignDispatch {
        if (! $this->isCartCallSignalsEnabled() || ! $this->isCartSiteEventsEnabled()) {
            return null;
        }

        return $this->dispatchSiteEvent(
            cart: $cart,
            siteEvent: ActiveCampaignSiteEvent::CallAttempted,
            cartEvent: $cartEvent,
            sourceEventType: CartEventType::CallAttempted->value,
            idempotencyKey: $this->idempotencyKeyForCallAttemptedSiteEvent($interactionId),
            payloadExtras: $this->callSignalPayloadExtras($cartEvent, $interactionId),
        );
    }

    /**
     * @return list<ActiveCampaignDispatch>
     */
    private function enqueueAppointmentPendingTagRemovesForCart(Cart $cart, string $sourceEventType): array
    {
        if (! $this->isCartAppointmentSignalsEnabled() || ! $this->isCartTagRemoveEnabled()) {
            return [];
        }

        $dispatches = [];

        CartEvent::query()
            ->where('cart_id', $cart->id)
            ->where('event', CartEventType::AppointmentPending5m->value)
            ->orderBy('id')
            ->get()
            ->each(function (CartEvent $pendingEvent) use ($cart, $sourceEventType, &$dispatches) {
                $appointmentId = $this->resolveAppointmentIdFromCartEvent($pendingEvent);
                if ($appointmentId === null) {
                    return;
                }

                $dispatch = $this->dispatchTagRemove(
                    cart: $cart,
                    tagKey: 'cart.appointment_pending',
                    eventType: $sourceEventType,
                    idempotencyKey: $this->idempotencyKeyForAppointmentPendingTagRemove($appointmentId),
                    payloadExtras: $this->appointmentSignalPayloadExtras($pendingEvent, $appointmentId),
                );

                if ($dispatch instanceof ActiveCampaignDispatch) {
                    $dispatches[] = $dispatch;
                }
            });

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

    private function resolveAppointmentIdFromCartEvent(CartEvent $cartEvent): ?int
    {
        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $appointmentId = $metadata['appointment_id'] ?? $metadata['laboratory_appointment_id'] ?? null;

        return is_numeric($appointmentId) ? (int) $appointmentId : null;
    }

    private function resolveInteractionIdFromCartEvent(CartEvent $cartEvent): ?int
    {
        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $interactionId = $metadata['interaction_id'] ?? null;

        return is_numeric($interactionId) ? (int) $interactionId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentSignalPayloadExtras(CartEvent $cartEvent, ?int $appointmentId = null): array
    {
        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];
        $appointmentId ??= $this->resolveAppointmentIdFromCartEvent($cartEvent);

        return array_merge(
            $this->baseCartEventPayloadExtras($cartEvent, $metadata, null),
            array_filter([
                'appointment_id' => $appointmentId,
                'brand' => $metadata['brand'] ?? null,
            ], static fn ($value) => $value !== null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function callSignalPayloadExtras(CartEvent $cartEvent, int $interactionId): array
    {
        $metadata = is_array($cartEvent->metadata) ? $cartEvent->metadata : [];

        return array_merge(
            $this->baseCartEventPayloadExtras($cartEvent, $metadata, null),
            array_filter([
                'appointment_id' => $this->resolveAppointmentIdFromCartEvent($cartEvent),
                'interaction_id' => $interactionId,
                'brand' => $metadata['brand'] ?? null,
            ], static fn ($value) => $value !== null),
        );
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

<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\Customer;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use App\Services\Laboratory\LaboratoryAppointmentCheckoutResolver;
use App\Services\Laboratory\LaboratoryCheckoutFlowEligibility;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CartAbandonmentService
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
        private ActiveCampaignOutboundDispatcher $activeCampaignOutboundDispatcher,
        private LaboratoryCheckoutFlowEligibility $checkoutFlowEligibility,
    ) {}

    public function abandonedAfterMinutes(): int
    {
        return Cart::abandonedAfterMinutes();
    }

    public function isEligibleForAbandonmentDetection(Cart $cart): bool
    {
        if ($cart->status !== MonitoringCartStatus::Active) {
            return false;
        }

        if ($cart->isEmptyActiveMonitoringCart()) {
            return false;
        }

        return $cart->items()->exists();
    }

    public function wasInactiveBeyondThreshold(CarbonInterface $lastActivityAt): bool
    {
        return $lastActivityAt->lte(now()->subMinutes($this->abandonedAfterMinutes()));
    }

    public function openAbandonedEpisode(Cart $cart): ?int
    {
        $lastAbandoned = $this->lastAbandonedEvent($cart);

        if ($lastAbandoned === null) {
            return null;
        }

        $episode = (int) ($lastAbandoned->metadata['episode'] ?? 0);

        if ($episode <= 0) {
            return null;
        }

        if ($this->hasResumedEpisode($cart, $episode)) {
            return null;
        }

        if ($this->wasInvalidatedByEmptying($cart, $lastAbandoned)) {
            return null;
        }

        return $episode;
    }

    public function nextEpisodeNumber(Cart $cart): int
    {
        return $cart->events()
            ->where('event', CartEventType::CartAbandoned->value)
            ->count() + 1;
    }

    public function recordAbandoned(Cart $cart): ?CartEvent
    {
        if (! $this->isEligibleForAbandonmentDetection($cart)) {
            return null;
        }

        if ($this->openAbandonedEpisode($cart) !== null) {
            return null;
        }

        $context = $this->laboratoryAbandonmentContext($cart);

        if ($context['exclude']) {
            return null;
        }

        $lastActivityAt = $context['reference_at']->copy();

        if (! $this->wasInactiveBeyondThreshold($lastActivityAt)) {
            return null;
        }

        $episode = $this->nextEpisodeNumber($cart);
        $abandonedAt = $lastActivityAt->copy()->addMinutes($this->abandonedAfterMinutes());
        $minutesInactive = max($this->abandonedAfterMinutes(), (int) floor($lastActivityAt->diffInMinutes(now())));

        $metadata = [
            'episode' => $episode,
            'last_activity_at' => $lastActivityAt->toIso8601String(),
            'abandoned_at' => $abandonedAt->toIso8601String(),
            'minutes_inactive' => $minutesInactive,
            'brand' => $this->resolvePrimaryBrand($cart),
            ...$context['metadata'],
        ];

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartAbandoned,
            "cart:{$cart->id}:abandoned:episode:{$episode}",
            $metadata,
            $abandonedAt,
            'cart_abandonment_detector',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function maybeRecordResumed(Cart $cart, ?array $clientContext = null): ?CartEvent
    {
        if (! $this->isEligibleForAbandonmentDetection($cart)) {
            return null;
        }

        $episode = $this->openAbandonedEpisode($cart);

        if ($episode === null) {
            return null;
        }

        if (! $this->wasInactiveBeyondThreshold(app(CartUserActivityResolver::class)->lastUserActivityAt($cart))) {
            return null;
        }

        $lastAbandoned = $this->lastAbandonedEvent($cart);

        if ($lastAbandoned === null) {
            return null;
        }

        $abandonedAt = $this->parseMetadataTimestamp($lastAbandoned->metadata['abandoned_at'] ?? null)
            ?? $lastAbandoned->occurred_at;
        $resumedAt = now();
        $durationSeconds = max(0, $abandonedAt->diffInSeconds($resumedAt));

        $metadata = [
            'episode' => $episode,
            'abandoned_at' => $abandonedAt->toIso8601String(),
            'resumed_at' => $resumedAt->toIso8601String(),
            'abandoned_duration_seconds' => $durationSeconds,
            'abandoned_duration_minutes' => (int) floor($durationSeconds / 60),
            'brand' => $this->resolvePrimaryBrand($cart),
        ];

        if ($clientContext !== null && $clientContext !== []) {
            $metadata['client'] = $clientContext;
        }

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartResumed,
            "cart:{$cart->id}:resumed:episode:{$episode}",
            $metadata,
            $resumedAt,
            'cart_activity',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function maybeRecordResumedForCustomer(
        Customer $customer,
        MonitoringCartType $type,
        ?array $clientContext = null,
    ): void {
        if (! $customer->user_id) {
            return;
        }

        $this->activeOperationalCartsForCustomer($customer, $type)
            ->each(fn (Cart $cart) => $this->maybeRecordResumed($cart, $clientContext));
    }

    public function recordRecoveredIfEligible(Cart $cart, ?int $purchaseId = null, ?array $clientContext = null): ?CartEvent
    {
        $abandonedEvents = $cart->events()
            ->where('event', CartEventType::CartAbandoned->value)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($abandonedEvents->isEmpty()) {
            return null;
        }

        $lastAbandoned = $abandonedEvents->last();
        $lastEpisode = (int) ($lastAbandoned->metadata['episode'] ?? $abandonedEvents->count());
        $lastAbandonedAt = $this->parseMetadataTimestamp($lastAbandoned->metadata['abandoned_at'] ?? null)
            ?? $lastAbandoned->occurred_at;

        $metadata = [
            'episodes_count' => $abandonedEvents->count(),
            'last_episode' => $lastEpisode,
            'last_abandoned_at' => $lastAbandonedAt->toIso8601String(),
            'recovered_at' => now()->toIso8601String(),
            'brand' => $this->resolvePrimaryBrand($cart),
        ];

        if ($purchaseId !== null) {
            $metadata['purchase_id'] = $purchaseId;
        }

        if ($clientContext !== null && $clientContext !== []) {
            $metadata['client'] = $clientContext;
        }

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CartRecovered,
            "cart:{$cart->id}:recovered",
            $metadata,
            source: 'cart_abandonment',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }

    /**
     * @return Collection<int, Cart>
     */
    public function cartsEligibleForAbandonmentDetection(): Collection
    {
        return Cart::query()
            ->operationalMonitoring()
            ->where('status', MonitoringCartStatus::Active)
            ->with(['items', 'user.customer'])
            ->get()
            ->filter(fn (Cart $cart) => $this->isEligibleForAbandonmentDetection($cart))
            ->reject(fn (Cart $cart) => $this->laboratoryAbandonmentContext($cart)['exclude'])
            ->values();
    }

    public function abandonmentReferenceAt(Cart $cart): CarbonInterface
    {
        return $this->laboratoryAbandonmentContext($cart)['reference_at']->copy();
    }

    /**
     * @return array{
     *     exclude: bool,
     *     reference_at: CarbonInterface,
     *     metadata: array<string, mixed>,
     * }
     */
    private function laboratoryAbandonmentContext(Cart $cart): array
    {
        $userActivityResolver = app(CartUserActivityResolver::class);
        $referenceAt = $userActivityResolver->lastUserActivityAt($cart);

        if ($cart->type !== MonitoringCartType::Lab) {
            return [
                'exclude' => false,
                'reference_at' => $referenceAt,
                'metadata' => [],
            ];
        }

        $brandValue = $this->resolvePrimaryBrand($cart);
        if ($brandValue === null) {
            return [
                'exclude' => false,
                'reference_at' => $referenceAt,
                'metadata' => [],
            ];
        }

        $customer = $cart->user?->customer;
        if ($customer === null) {
            return [
                'exclude' => false,
                'reference_at' => $referenceAt,
                'metadata' => [],
            ];
        }

        $brand = LaboratoryBrand::from($brandValue);

        if ($this->appointmentCheckoutResolver()->isAwaitingConcierge($customer, $brand)) {
            return [
                'exclude' => true,
                'reference_at' => $referenceAt,
                'metadata' => [],
            ];
        }

        $confirmedUnpaid = $this->appointmentCheckoutResolver()->confirmedUnpaidAppointment($customer, $brand);
        $metadata = [];

        if ($confirmedUnpaid?->confirmed_at !== null) {
            $confirmedAt = $confirmedUnpaid->confirmed_at->copy();
            if ($confirmedAt->gt($referenceAt)) {
                $referenceAt = $confirmedAt;
            }

            $usesAppointmentFirst = $this->checkoutFlowEligibility->usesAppointmentFirstFlow($customer, $brand);
            $metadata = [
                'checkout_stage' => $usesAppointmentFirst ? 'payment' : 'confirmation',
                'flow' => $usesAppointmentFirst ? 'appointment_first' : 'standard',
                'appointment_id' => $confirmedUnpaid->id,
                'appointment_confirmed_at' => $confirmedUnpaid->confirmed_at->toIso8601String(),
            ];
        }

        return [
            'exclude' => false,
            'reference_at' => $referenceAt,
            'metadata' => $metadata,
        ];
    }

    private function appointmentCheckoutResolver(): LaboratoryAppointmentCheckoutResolver
    {
        return app(LaboratoryAppointmentCheckoutResolver::class);
    }

    private function lastAbandonedEvent(Cart $cart): ?CartEvent
    {
        return $cart->events()
            ->where('event', CartEventType::CartAbandoned->value)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    private function hasResumedEpisode(Cart $cart, int $episode): bool
    {
        return $cart->events()
            ->where('event', CartEventType::CartResumed->value)
            ->get()
            ->contains(fn (CartEvent $event) => (int) ($event->metadata['episode'] ?? 0) === $episode);
    }

    private function wasInvalidatedByEmptying(Cart $cart, CartEvent $lastAbandoned): bool
    {
        if ($cart->events()
            ->where('event', CartEventType::CartEmptied->value)
            ->where('occurred_at', '>', $lastAbandoned->occurred_at)
            ->exists()) {
            return true;
        }

        $lastActivityAt = $this->parseMetadataTimestamp($lastAbandoned->metadata['last_activity_at'] ?? null);

        if ($lastActivityAt === null) {
            return false;
        }

        return $cart->events()
            ->where('event', CartEventType::CartEmptied->value)
            ->where('occurred_at', '>=', $lastActivityAt)
            ->exists();
    }

    /**
     * @return Collection<int, Cart>
     */
    private function activeOperationalCartsForCustomer(Customer $customer, MonitoringCartType $type): Collection
    {
        return Cart::query()
            ->where('user_id', $customer->user_id)
            ->where('type', $type)
            ->where('status', MonitoringCartStatus::Active)
            ->whereHas('items')
            ->get();
    }

    private function resolvePrimaryBrand(Cart $cart): ?string
    {
        $brands = collect($cart->labBrands())->pluck('value')->filter()->values();

        if ($brands->count() === 1) {
            return (string) $brands->first();
        }

        return null;
    }

    private function parseMetadataTimestamp(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Services\Otp\Monitoring;

use App\Enums\Otp\OtpMovementFlow;
use App\Enums\Otp\OtpMovementStage;
use App\Enums\Otp\OtpMovementStatus;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpMovementEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class OtpMovementQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     events: LengthAwarePaginator,
     *     summary: array<string, int>,
     *     options: array<string, mixed>
     * }
     */
    public function paginate(array $filters, Carbon $startDate, Carbon $endDate): array
    {
        $query = $this->buildEventQuery($filters, $startDate, $endDate);

        $events = (clone $query)
            ->with(['user.customer'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $events->getCollection()->transform(fn (OtpMovementEvent $event) => $this->formatListRow($event));

        $historical = $this->historicalRows($filters, $startDate, $endDate);
        if ($historical->isNotEmpty() && ($filters['include_historical'] ?? true)) {
            $this->mergeHistoricalIntoPaginator($events, $historical, $filters);
        }

        return [
            'events' => $events,
            'summary' => $this->buildSummary($filters, $startDate, $endDate),
            'options' => $this->filterOptions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildEventQuery(array $filters, Carbon $startDate, Carbon $endDate): Builder
    {
        $query = OtpMovementEvent::query()
            ->whereBetween('occurred_at', [$startDate, $endDate]);

        if (! empty($filters['flow'])) {
            $query->where('flow', $filters['flow']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (! empty($filters['provider'])) {
            $query->where('provider_alias', $filters['provider']);
        }

        if (! empty($filters['endpoint'])) {
            $query->where('endpoint', 'like', '%'.$filters['endpoint'].'%');
        }

        if (! empty($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }

        if (! empty($filters['challenge_id'])) {
            $query->where('challenge_public_id', $filters['challenge_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['destination'])) {
            $this->applyDestinationFilter($query, (string) $filters['destination']);
        }

        if (! empty($filters['failed_only'])) {
            $query->where('status', OtpMovementStatus::Failed->value);
        }

        if (! empty($filters['replay_only'])) {
            $query->where(function (Builder $q): void {
                $q->where('is_replay', true)
                    ->orWhere('is_idempotency_conflict', true);
            });
        }

        return $query;
    }

    private function applyDestinationFilter(Builder $query, string $needle): void
    {
        $needle = trim($needle);
        if ($needle === '') {
            return;
        }

        if (str_contains($needle, '@')) {
            $query->where('destination_masked', 'like', '%'.$this->maskEmailSearch($needle).'%');

            return;
        }

        $digits = preg_replace('/\D+/', '', $needle) ?? '';
        if (strlen($digits) >= 4) {
            $query->where('destination_masked', 'like', '%'.substr($digits, -4).'%');
        }
    }

    private function maskEmailSearch(string $email): string
    {
        $parts = explode('@', strtolower($email), 2);
        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.'***@'.$domain;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function buildSummary(array $filters, Carbon $startDate, Carbon $endDate): array
    {
        $base = $this->buildEventQuery($filters, $startDate, $endDate);

        return [
            'requests' => (int) (clone $base)->whereIn('stage', [
                OtpMovementStage::RequestReceived->value,
                OtpMovementStage::ChallengeCreated->value,
                OtpMovementStage::DecoyIssued->value,
                OtpMovementStage::ResendRequested->value,
            ])->count(),
            'delivery_attempts' => (int) (clone $base)->whereIn('stage', [
                OtpMovementStage::DeliveryAttempted->value,
                OtpMovementStage::DeliveryAccepted->value,
                OtpMovementStage::DeliveryFailed->value,
                OtpMovementStage::DeliveryFallback->value,
            ])->count(),
            'provider_accepted' => (int) (clone $base)->where('stage', OtpMovementStage::DeliveryAccepted->value)->count(),
            'verified' => (int) (clone $base)->where('stage', OtpMovementStage::VerifySucceeded->value)->count(),
            'failed' => (int) (clone $base)->where('status', OtpMovementStatus::Failed->value)->count(),
            'replays' => (int) (clone $base)->where(function (Builder $q): void {
                $q->where('is_replay', true)->orWhere('is_idempotency_conflict', true);
            })->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showMovement(string $movementKey): array
    {
        $events = OtpMovementEvent::query()
            ->where('movement_key', $movementKey)
            ->with(['user.customer', 'deliveryOperation', 'challenge'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $historicalEvents = collect();
        $partial = false;

        if ($events->isEmpty()) {
            $historicalEvents = $this->reconstructHistoricalTimeline($movementKey);
            $partial = true;
        } elseif ($events->count() < 3) {
            $historicalEvents = $this->reconstructHistoricalTimeline($movementKey);
            if ($historicalEvents->isNotEmpty()) {
                $partial = true;
            }
        }

        $timeline = collect($events)
            ->map(fn (OtpMovementEvent $e) => $this->formatTimelineEntry($e, false))
            ->merge($historicalEvents)
            ->sortBy([
                ['occurred_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();

        $latest = $events->last();
        $diagnosis = app(OtpMovementDiagnosisBuilder::class)->build($timeline, $partial);

        return [
            'movement_key' => $movementKey,
            'partial_traceability' => $partial,
            'diagnosis' => $diagnosis,
            'timeline' => $timeline,
            'identifiers' => [
                'correlation_id' => $latest?->correlation_id ?? $this->extractCorrelationId($movementKey, $timeline),
                'challenge_id' => $latest?->challenge_public_id ?? $this->extractChallengeId($movementKey, $timeline),
            ],
            'subject' => $this->formatSubject($latest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListRow(OtpMovementEvent $event): array
    {
        return [
            'id' => $event->id,
            'movement_key' => $event->movement_key,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'flow' => $event->flow,
            'flow_label' => OtpMovementFlow::tryFrom($event->flow)?->label() ?? $event->flow,
            'operation' => $event->operation,
            'stage' => $event->stage,
            'stage_label' => OtpMovementStage::tryFrom($event->stage)?->label() ?? $event->stage,
            'status' => $event->status,
            'status_label' => OtpMovementStatus::tryFrom($event->status)?->label() ?? $event->status,
            'status_color' => OtpMovementStatus::tryFrom($event->status)?->badgeColor() ?? 'zinc',
            'channel' => $event->channel,
            'destination_masked' => $event->destination_masked,
            'user' => $this->formatSubject($event),
            'challenge_id' => $event->challenge_public_id,
            'correlation_id' => $event->correlation_id,
            'provider' => $event->provider_alias,
            'provider_result_class' => $event->provider_result_class,
            'http_status' => $event->http_status,
            'attempt_number' => $event->attempt_number,
            'is_replay' => $event->is_replay,
            'is_idempotency_conflict' => $event->is_idempotency_conflict,
            'is_decoy' => $event->is_decoy,
            'endpoint' => $event->endpoint,
            'technical_message' => $event->technical_message,
            'idempotency_key_fingerprint' => $event->idempotency_key_fingerprint,
            'partial_traceability' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatSubject(?OtpMovementEvent $event): ?array
    {
        if ($event === null) {
            return null;
        }

        $user = $event->user;
        if ($user instanceof User) {
            return [
                'user_id' => $user->id,
                'customer_id' => $user->customer?->id ?? $event->customer_id,
                'name' => $user->full_name ?? trim(($user->name ?? '').' '.($user->paternal_lastname ?? '')),
                'email' => $user->email,
            ];
        }

        if ($event->customer_id !== null) {
            return [
                'customer_id' => $event->customer_id,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTimelineEntry(OtpMovementEvent $event, bool $partial): array
    {
        return [
            'id' => 'evt-'.$event->id,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'stage' => $event->stage,
            'stage_label' => OtpMovementStage::tryFrom($event->stage)?->label() ?? $event->stage,
            'status' => $event->status,
            'status_label' => OtpMovementStatus::tryFrom($event->status)?->label() ?? $event->status,
            'status_color' => OtpMovementStatus::tryFrom($event->status)?->badgeColor() ?? 'zinc',
            'channel' => $event->channel,
            'provider' => $event->provider_alias,
            'provider_result_class' => $event->provider_result_class,
            'http_status' => $event->http_status,
            'technical_message' => $event->technical_message,
            'is_historical' => false,
            'partial' => $partial,
            'meta' => $event->meta,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function reconstructHistoricalTimeline(string $movementKey): Collection
    {
        $challengePublicId = str_starts_with($movementKey, 'chal:')
            ? substr($movementKey, 5)
            : null;

        $correlationId = str_starts_with($movementKey, 'corr:')
            ? substr($movementKey, 5)
            : null;

        $entries = collect();

        $challengeQuery = OtpChallenge::query()->with(['user.customer']);
        if ($challengePublicId !== null) {
            $challengeQuery->where('public_id', $challengePublicId);
        } elseif ($correlationId !== null) {
            $challengeIds = OtpDeliveryOperation::query()
                ->where('correlation_id', $correlationId)
                ->pluck('otp_challenge_id')
                ->filter()
                ->all();
            if ($challengeIds === []) {
                return $entries;
            }
            $challengeQuery->whereIn('id', $challengeIds);
        } else {
            return $entries;
        }

        $challenge = $challengeQuery->first();
        if ($challenge === null) {
            return $entries;
        }

        $entries->push([
            'id' => 'hist-challenge-'.$challenge->id,
            'occurred_at' => $challenge->created_at?->toIso8601String(),
            'stage' => OtpMovementStage::ChallengeCreated->value,
            'stage_label' => OtpMovementStage::ChallengeCreated->label(),
            'status' => OtpMovementStatus::InProgress->value,
            'status_label' => OtpMovementStatus::InProgress->label(),
            'status_color' => OtpMovementStatus::InProgress->badgeColor(),
            'channel' => $challenge->channel,
            'provider' => null,
            'provider_result_class' => null,
            'http_status' => null,
            'technical_message' => 'Challenge histórico reconstruido desde otp_challenges.',
            'is_historical' => true,
            'partial' => true,
            'meta' => [
                'send_count' => $challenge->send_count,
                'failed_attempts' => $challenge->failed_attempts,
                'challenge_status' => $challenge->status(),
            ],
        ]);

        $deliveries = OtpDeliveryOperation::query()
            ->where('otp_challenge_id', $challenge->id)
            ->orderBy('created_at')
            ->get();

        foreach ($deliveries as $delivery) {
            $stage = str_contains((string) $delivery->status, 'accepted')
                ? OtpMovementStage::DeliveryAccepted
                : OtpMovementStage::DeliveryFailed;

            $entries->push([
                'id' => 'hist-delivery-'.$delivery->id,
                'occurred_at' => $delivery->created_at?->toIso8601String(),
                'stage' => $stage->value,
                'stage_label' => $stage->label(),
                'status' => $stage === OtpMovementStage::DeliveryAccepted
                    ? OtpMovementStatus::Sent->value
                    : OtpMovementStatus::Failed->value,
                'status_label' => $stage === OtpMovementStage::DeliveryAccepted
                    ? OtpMovementStatus::Sent->label()
                    : OtpMovementStatus::Failed->label(),
                'status_color' => $stage === OtpMovementStage::DeliveryAccepted
                    ? OtpMovementStatus::Sent->badgeColor()
                    : OtpMovementStatus::Failed->badgeColor(),
                'channel' => $delivery->primary_channel,
                'provider' => $delivery->provider_alias,
                'provider_result_class' => $delivery->result_class,
                'http_status' => null,
                'technical_message' => 'Operación de entrega histórica reconstruida desde otp_delivery_operations.',
                'is_historical' => true,
                'partial' => true,
                'meta' => [
                    'delivery_status' => $delivery->status,
                    'fallback_used' => $delivery->fallback_used,
                ],
            ]);
        }

        if ($challenge->isConsumed()) {
            $entries->push([
                'id' => 'hist-verify-'.$challenge->id,
                'occurred_at' => $challenge->consumed_at?->toIso8601String(),
                'stage' => OtpMovementStage::VerifySucceeded->value,
                'stage_label' => OtpMovementStage::VerifySucceeded->label(),
                'status' => OtpMovementStatus::Verified->value,
                'status_label' => OtpMovementStatus::Verified->label(),
                'status_color' => OtpMovementStatus::Verified->badgeColor(),
                'channel' => $challenge->channel,
                'provider' => null,
                'provider_result_class' => null,
                'http_status' => null,
                'technical_message' => 'Verificación inferida por consumed_at en otp_challenges.',
                'is_historical' => true,
                'partial' => true,
                'meta' => null,
            ]);
        } elseif ($challenge->isExpired()) {
            $entries->push([
                'id' => 'hist-expired-'.$challenge->id,
                'occurred_at' => $challenge->expires_at?->toIso8601String(),
                'stage' => OtpMovementStage::VerifyExpired->value,
                'stage_label' => OtpMovementStage::VerifyExpired->label(),
                'status' => OtpMovementStatus::Expired->value,
                'status_label' => OtpMovementStatus::Expired->label(),
                'status_color' => OtpMovementStatus::Expired->badgeColor(),
                'channel' => $challenge->channel,
                'provider' => null,
                'provider_result_class' => null,
                'http_status' => null,
                'technical_message' => 'Challenge expirado inferido desde otp_challenges.',
                'is_historical' => true,
                'partial' => true,
                'meta' => null,
            ]);
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $timeline
     */
    private function extractCorrelationId(string $movementKey, array $timeline): ?string
    {
        if (str_starts_with($movementKey, 'corr:')) {
            return substr($movementKey, 5);
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $timeline
     */
    private function extractChallengeId(string $movementKey, array $timeline): ?string
    {
        if (str_starts_with($movementKey, 'chal:')) {
            return substr($movementKey, 5);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function historicalRows(array $filters, Carbon $startDate, Carbon $endDate): Collection
    {
        if (! empty($filters['replay_only']) || ! empty($filters['stage'])) {
            return collect();
        }

        $existingChallengeIds = OtpMovementEvent::query()
            ->whereBetween('occurred_at', [$startDate, $endDate])
            ->whereNotNull('otp_challenge_id')
            ->pluck('otp_challenge_id');

        $challenges = OtpChallenge::query()
            ->with(['user.customer'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($existingChallengeIds->isNotEmpty(), fn (Builder $q) => $q->whereNotIn('id', $existingChallengeIds))
            ->when(! empty($filters['flow']), fn (Builder $q) => $q->where('purpose', $filters['flow']))
            ->when(! empty($filters['challenge_id']), fn (Builder $q) => $q->where('public_id', $filters['challenge_id']))
            ->when(! empty($filters['user_id']), fn (Builder $q) => $q->where('user_id', (int) $filters['user_id']))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $challenges->map(function (OtpChallenge $challenge): array {
            $delivery = OtpDeliveryOperation::query()
                ->where('otp_challenge_id', $challenge->id)
                ->latest('id')
                ->first();

            $stage = OtpMovementStage::ChallengeCreated;
            $status = OtpMovementStatus::InProgress;

            if ($challenge->isConsumed()) {
                $stage = OtpMovementStage::VerifySucceeded;
                $status = OtpMovementStatus::Verified;
            } elseif ($challenge->isExpired()) {
                $stage = OtpMovementStage::VerifyExpired;
                $status = OtpMovementStatus::Expired;
            } elseif ($delivery !== null && str_contains((string) $delivery->status, 'accepted')) {
                $stage = OtpMovementStage::DeliveryAccepted;
                $status = OtpMovementStatus::Sent;
            } elseif ($delivery !== null) {
                $stage = OtpMovementStage::DeliveryFailed;
                $status = OtpMovementStatus::Failed;
            }

            return [
                'id' => 'hist-'.$challenge->id,
                'movement_key' => 'chal:'.$challenge->public_id,
                'occurred_at' => $challenge->created_at?->toIso8601String(),
                'flow' => $challenge->purpose,
                'flow_label' => OtpMovementFlow::tryFrom($challenge->purpose)?->label() ?? $challenge->purpose,
                'operation' => 'historical',
                'stage' => $stage->value,
                'stage_label' => $stage->label(),
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_color' => $status->badgeColor(),
                'channel' => $challenge->channel,
                'destination_masked' => $challenge->destination_masked,
                'user' => $challenge->user ? [
                    'user_id' => $challenge->user->id,
                    'customer_id' => $challenge->user->customer?->id,
                    'name' => $challenge->user->full_name ?? $challenge->user->name,
                    'email' => $challenge->user->email,
                ] : null,
                'challenge_id' => $challenge->public_id,
                'correlation_id' => $delivery?->correlation_id,
                'provider' => $delivery?->provider_alias,
                'provider_result_class' => $delivery?->result_class,
                'http_status' => null,
                'attempt_number' => $delivery?->attempt_count ?? 1,
                'is_replay' => false,
                'is_idempotency_conflict' => false,
                'is_decoy' => false,
                'endpoint' => null,
                'technical_message' => 'Trazabilidad parcial reconstruida desde tablas históricas.',
                'idempotency_key_fingerprint' => null,
                'partial_traceability' => true,
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $historical
     * @param  array<string, mixed>  $filters
     */
    private function mergeHistoricalIntoPaginator(
        LengthAwarePaginator $events,
        Collection $historical,
        array $filters,
    ): void {
        if ($historical->isEmpty()) {
            return;
        }

        $merged = $events->getCollection()
            ->concat($historical)
            ->sortByDesc('occurred_at')
            ->values();

        $events->setCollection($merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'flows' => collect(OtpMovementFlow::cases())->map(fn (OtpMovementFlow $f) => [
                'value' => $f->value,
                'label' => $f->label(),
            ])->values()->all(),
            'statuses' => collect(OtpMovementStatus::cases())->map(fn (OtpMovementStatus $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ])->values()->all(),
            'stages' => collect(OtpMovementStage::cases())->map(fn (OtpMovementStage $s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ])->values()->all(),
            'channels' => ['sms', 'email'],
            'providers' => OtpDeliveryOperation::query()
                ->select('provider_alias')
                ->whereNotNull('provider_alias')
                ->distinct()
                ->orderBy('provider_alias')
                ->pluck('provider_alias')
                ->values()
                ->all(),
        ];
    }
}

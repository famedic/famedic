<?php

namespace App\Services\LaboratoryConfirmation;

use App\Enums\GdaOrderStatus;
use App\Jobs\CoordinateLaboratoryPurchaseConfirmationJob;
use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseConfirmation as Confirmation;
use App\Models\LaboratoryPurchasePreparationSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class LaboratoryConfirmationCoordinator
{
    public function __construct(
        private readonly LaboratoryConfirmationContent $content,
        private readonly LaboratoryConfirmationTransport $transport,
    ) {}

    public function activation(): ?CarbonImmutable
    {
        $value = config('laboratory-confirmation.activated_at');
        if (! config('laboratory-confirmation.enabled', false) || ! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        try {
            $activation = CarbonImmutable::parse($value, 'UTC');

            return $activation->isFuture() ? null : $activation;
        } catch (Throwable) {
            return null;
        }
    }

    public function appliesTo(LaboratoryPurchase $purchase): bool
    {
        $activation = $this->cohortActivation();

        return $activation !== null && $purchase->created_at?->greaterThanOrEqualTo($activation);
    }

    private function cohortActivation(): ?CarbonImmutable
    {
        // Persist the cohort boundary once. Pausing rollout must never send legacy mail
        // for a cohort that the reconciler would later enroll again on resume.
        if (Schema::hasTable('laboratory_confirmation_rollouts')) {
            $stored = DB::table('laboratory_confirmation_rollouts')->where('key', 'purchase_confirmation')->value('activated_at');
            if ($stored) {
                return CarbonImmutable::parse($stored, 'UTC');
            }
        }
        $activation = $this->activation();
        if ($activation && Schema::hasTable('laboratory_confirmation_rollouts')) {
            DB::table('laboratory_confirmation_rollouts')->insertOrIgnore(['key' => 'purchase_confirmation', 'activated_at' => $activation]);

            return CarbonImmutable::parse(DB::table('laboratory_confirmation_rollouts')->where('key', 'purchase_confirmation')->value('activated_at'), 'UTC');
        }

        return $activation;
    }

    public function hasIntent(LaboratoryPurchase $purchase): bool
    {
        return Schema::hasTable('laboratory_purchase_confirmations')
            && Confirmation::where('idempotency_key', $this->automaticKey($purchase))->exists();
    }

    public function suppressesSummaryMail(LaboratoryPurchase $purchase): bool
    {
        // An enrolled purchase never returns to the legacy second-mail path after a flag rollback.
        return $this->appliesTo($purchase) || $this->hasIntent($purchase);
    }

    public function enrollAfterCommit(LaboratoryPurchase $purchase): bool
    {
        try {
            if (! $this->appliesTo($purchase)) {
                return false;
            }
            DB::afterCommit(function () use ($purchase) {
                try {
                    $fresh = $purchase->fresh();
                    if ($fresh) {
                        $this->register($fresh);
                    }
                } catch (Throwable) {
                    $this->log('registration_failed', $purchase->id);
                }
            });
        } catch (Throwable) {
            $this->log('registration_callback_failed', $purchase->id);

            return $this->activation() !== null;
        }

        return true;
    }

    public function register(LaboratoryPurchase $purchase, bool $recovery = false): ?Confirmation
    {
        if (! $this->appliesTo($purchase) || ! $this->confirmedPurchase($purchase)) {
            return null;
        }
        // Recovery cannot reconstruct a lost commit timestamp: use creation as a conservative
        // lower bound, never now(), so a registration retry cannot renew the content budget.
        $confirmedAt = $recovery ? $purchase->created_at : now();
        $wait = max(0, min(60, (int) config('laboratory-confirmation.content_wait_seconds', 15)));
        $intent = Confirmation::firstOrCreate(['idempotency_key' => $this->automaticKey($purchase)], [
            'laboratory_purchase_id' => $purchase->id, 'event_type' => 'purchase_confirmation',
            'confirmed_at' => $confirmedAt, 'deadline_at' => $confirmedAt->copy()->addSeconds($wait),
            'status' => 'waiting', 'next_attempt_at' => now(),
        ]);
        $this->dispatch($intent);

        return $intent;
    }

    public function usesDurableDelivery(): bool
    {
        return $this->cohortActivation() !== null;
    }

    public function requestResend(LaboratoryPurchase $purchase, int $actorId): Confirmation
    {
        if (! $this->usesDurableDelivery()) {
            throw new \RuntimeException('confirmation_rollout_disabled');
        }
        $intent = Confirmation::create([
            'idempotency_key' => 'manual:'.Str::uuid(), 'event_type' => 'administrative_resend',
            'laboratory_purchase_id' => $purchase->id, 'requested_by' => $actorId,
            'confirmed_at' => now(), 'deadline_at' => now(), 'status' => 'waiting', 'next_attempt_at' => now(),
        ]);
        $this->dispatch($intent);

        return $intent;
    }

    public function wake(LaboratoryPurchase $purchase, bool $failed = false): void
    {
        try {
            if (! Schema::hasTable('laboratory_purchase_confirmations')) {
                return;
            }
            $intent = Confirmation::where('idempotency_key', $this->automaticKey($purchase))->first();
            if ($intent && $intent->status === 'waiting') {
                if ($failed) {
                    Confirmation::whereKey($intent->id)->where('status', 'waiting')->update(['error_code' => 'preparation_failed']);
                }
                $this->dispatch($intent);
            }
        } catch (Throwable) {
            $this->log('wake_failed', $purchase->id);
        }
    }

    public function dispatch(Confirmation $intent, mixed $at = null): void
    {
        try {
            if (! $this->asyncConfigurationValid()) {
                $this->log('independent_async_workers_required', $intent->laboratory_purchase_id, $intent->id);

                return;
            }
            CoordinateLaboratoryPurchaseConfirmationJob::dispatch($intent->id)->delay($at ?? now());
        } catch (Throwable) {
            // The ledger is the outbox. The reconciler can safely dispatch the same ID again.
            $this->log('dispatch_failed', $intent->laboratory_purchase_id, $intent->id);
        }
    }

    public function coordinate(int $id): void
    {
        if (! $this->activation() || ! $this->asyncConfigurationValid()) {
            return;
        }
        $intent = Confirmation::find($id);
        if (! $intent || in_array($intent->status, ['accepted', 'ambiguous', 'failed', 'cancelled', 'sending', 'preparing'], true)) {
            return;
        }
        if ($intent->next_attempt_at?->isFuture()) {
            return;
        }
        if ($intent->status === 'waiting') {
            $purchase = LaboratoryPurchase::with(['customer.user', 'laboratoryPurchaseItems', 'preparationSummary.aiExecution'])->find($intent->laboratory_purchase_id);
            if (! $purchase || ($intent->event_type === 'purchase_confirmation' && ! $this->confirmedPurchase($purchase))) {
                Confirmation::whereKey($id)->where('status', 'waiting')->update(['status' => 'cancelled', 'error_code' => 'purchase_unavailable']);

                return;
            }
            try {
                $presentation = $this->content->presentation($purchase);
            } catch (Throwable) {
                Confirmation::whereKey($id)->where('status', 'waiting')->update([
                    'status' => $intent->preparation_attempts >= 2 ? 'failed' : 'waiting',
                    'preparation_attempts' => DB::raw('preparation_attempts + 1'),
                    'next_attempt_at' => now()->addSeconds(30), 'error_code' => 'presentation_unavailable',
                ]);
                $this->log('presentation_unavailable', $purchase->id, $id);

                return;
            }
            $summary = $purchase->preparationSummary;
            $auto = config('services.laboratory_preparation.deterministic_v3_enabled', false)
                && ($presentation['has_ai_summary'] ?? false)
                && $summary?->decision_status === LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED
                && $summary?->generated_at !== null && $summary->generated_at->lessThanOrEqualTo($intent->deadline_at);
            $knownOriginal = $intent->error_code === 'preparation_failed' || ! config('services.laboratory_preparation.deterministic_v3_enabled', false)
                || $summary?->isFallbackOriginal()
                || ($presentation['ai_status'] ?? null) === 'AI_FAILED'
                || $this->failedPreparationWithoutSummary($purchase);
            if (! $auto && ! $knownOriginal && $intent->deadline_at->isFuture()) {
                $this->dispatch($intent, $intent->deadline_at);

                return;
            }
            $token = (string) Str::uuid();
            $claimed = Confirmation::whereKey($id)->where('status', 'waiting')->update([
                'status' => 'preparing', 'attempt_id' => $token,
                'lease_until' => now()->addSeconds($this->leaseSeconds()),
                'preparation_attempts' => DB::raw('preparation_attempts + 1'),
            ]);
            if (! $claimed) {
                return;
            }
            try {
                $payload = $this->content->snapshot($purchase, $presentation, $auto);
                // Model assignment is required for the encrypted cast (query update bypasses it).
                $encrypted = new Confirmation(['payload' => $payload]);
                Confirmation::whereKey($id)->where('status', 'preparing')->where('attempt_id', $token)->update([
                    'status' => 'ready', 'snapshot_version' => $token, 'content_kind' => $auto ? 'auto' : 'original',
                    'source_hash' => $presentation['source_hash'] ?? null,
                    'payload' => $encrypted->getAttributes()['payload'],
                    'lease_until' => null, 'next_attempt_at' => now(), 'error_code' => null,
                ]);
            } catch (Throwable) {
                Confirmation::whereKey($id)->where('status', 'preparing')->where('attempt_id', $token)->update([
                    'status' => $intent->preparation_attempts >= 2 ? 'failed' : 'waiting',
                    'error_code' => 'content_preparation_failed', 'lease_until' => null,
                    'next_attempt_at' => now()->addSeconds(30),
                ]);

                return;
            }
        }
        $this->deliver($id);
    }

    private function deliver(int $id): void
    {
        $intent = Confirmation::findOrFail($id);
        if (! in_array($intent->status, ['ready', 'retry_wait'], true) || $intent->next_attempt_at?->isFuture()) {
            return;
        }
        if ($intent->content_kind === 'auto' && $intent->attempts === 0) {
            $purchase = LaboratoryPurchase::with('laboratoryPurchaseItems')->find($intent->laboratory_purchase_id);
            $current = $purchase ? $this->content->presentation($purchase) : [];
            if (! config('services.laboratory_preparation.deterministic_v3_enabled', false)
                || ! ($current['has_ai_summary'] ?? false) || ($current['source_hash'] ?? null) !== $intent->source_hash) {
                $this->snapshotQuery($intent)->where('status', 'ready')->where('attempts', 0)->update([
                    'status' => 'waiting', 'payload' => null, 'snapshot_version' => null, 'content_kind' => null, 'next_attempt_at' => now(),
                ]);
                $this->dispatch($intent);

                return;
            }
        }
        // Decrypt/validate before claiming transport: failures here cannot be ambiguous.
        try {
            if (! filled($intent->snapshot_version)) {
                throw new \RuntimeException('missing_snapshot_version');
            }
            $payload = $intent->payload;
            if (! is_array($payload) || ! isset($payload['html'], $payload['recipient'], $payload['subject'])) {
                throw new \RuntimeException('invalid_snapshot');
            }
        } catch (Throwable) {
            $this->snapshotQuery($intent)->whereIn('status', ['ready', 'retry_wait'])->update(['status' => 'failed', 'error_code' => 'snapshot_unavailable']);

            return;
        }
        $token = (string) Str::uuid();
        $claimed = $this->snapshotQuery($intent)->where('attempt_id', $intent->attempt_id)
            ->whereIn('status', ['ready', 'retry_wait'])
            ->where('attempts', '<', $this->maxAttempts())
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => 'sending', 'attempt_id' => $token, 'lease_until' => now()->addSeconds($this->leaseSeconds()),
                'attempts' => DB::raw('attempts + 1'), 'transport_status' => 'in_flight']);
        if (! $claimed) {
            return;
        }
        try {
            $messageId = $this->transport->send($payload, $token);
            Confirmation::whereKey($id)->where('attempt_id', $token)->whereIn('status', ['sending', 'ambiguous'])->update([
                'status' => 'accepted', 'transport_status' => 'accepted', 'accepted_at' => now(),
                'transport_message_id' => Str::limit($messageId, 200, ''), 'lease_until' => null, 'error_code' => null,
            ]);
        } catch (DeliveryNotAccepted) {
            $current = Confirmation::findOrFail($id);
            $retry = $current->attempts < $this->maxAttempts();
            $delays = config('laboratory-confirmation.retry_delays', [30, 120]);
            $next = now()->addSeconds(max(1, (int) ($delays[$current->attempts - 1] ?? 120)));
            Confirmation::whereKey($id)->where('status', 'sending')->where('attempt_id', $token)->update([
                'status' => $retry ? 'retry_wait' : 'failed', 'transport_status' => 'not_accepted',
                'next_attempt_at' => $next, 'lease_until' => null, 'error_code' => 'transport_rejected',
            ]);
            if ($retry) {
                $this->dispatch($current, $next);
            }
        } catch (Throwable) {
            // Includes timeouts, disconnects and uncertainty saving the provider's acknowledgement.
            Confirmation::whereKey($id)->where('status', 'sending')->where('attempt_id', $token)->update([
                'status' => 'ambiguous', 'transport_status' => 'unknown', 'lease_until' => null, 'error_code' => 'delivery_ambiguous',
            ]);
            $this->log('delivery_ambiguous', $intent->laboratory_purchase_id, $id);
        }
    }

    /** The UUID identifies rendered content, independently of source hash and delivery attempts. */
    private function snapshotQuery(Confirmation $intent): \Illuminate\Database\Eloquent\Builder
    {
        return Confirmation::whereKey($intent->id)->where('snapshot_version', $intent->snapshot_version);
    }

    public function reconcile(): array
    {
        $activation = $this->activation() ? $this->cohortActivation() : null;
        if (! $activation || ! Schema::hasTable('laboratory_purchase_confirmations')) {
            return ['registered' => 0, 'dispatched' => 0];
        }
        $registered = 0;
        LaboratoryPurchase::where('created_at', '>=', $activation)->where('gda_status', GdaOrderStatus::Confirmed->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('laboratory_purchase_confirmations as confirmations')
                ->whereColumn('confirmations.laboratory_purchase_id', 'laboratory_purchases.id')->where('confirmations.event_type', 'purchase_confirmation'))
            ->chunkById(100, function ($purchases) use (&$registered) {
                foreach ($purchases as $purchase) {
                    if ($this->register($purchase, recovery: true)) {
                        $registered++;
                    }
                }
            });
        Confirmation::where('status', 'sending')->where('lease_until', '<=', now())->update([
            'status' => 'ambiguous', 'transport_status' => 'unknown', 'error_code' => 'worker_interrupted', 'lease_until' => null,
        ]);
        Confirmation::where('status', 'preparing')->where('lease_until', '<=', now())->update([
            'status' => 'waiting', 'lease_until' => null, 'next_attempt_at' => now(), 'error_code' => 'preparation_interrupted',
        ]);
        $dispatched = 0;
        Confirmation::whereIn('status', ['waiting', 'ready', 'retry_wait'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->chunkById(100, function ($intents) use (&$dispatched) {
                foreach ($intents as $intent) {
                    $this->dispatch($intent);
                    $dispatched++;
                }
            });

        return compact('registered', 'dispatched');
    }

    public function asyncConfigurationValid(): bool
    {
        $connection = config('laboratory-confirmation.connection', 'database');
        $driver = config("queue.connections.{$connection}.driver");

        return in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)
            && filled(config('laboratory-confirmation.queue')) && filled(config('laboratory-confirmation.generation_queue'))
            && config('laboratory-confirmation.queue') !== config('laboratory-confirmation.generation_queue');
    }

    private function confirmedPurchase(LaboratoryPurchase $purchase): bool
    {
        return ! $purchase->trashed() && $purchase->gda_status === GdaOrderStatus::Confirmed
            && filled($purchase->gda_order_id) && trim((string) $purchase->gda_order_id) !== '0'
            && (int) $purchase->gda_consecutivo > 0;
    }

    private function failedPreparationWithoutSummary(LaboratoryPurchase $purchase): bool
    {
        if ($purchase->preparationSummary !== null) {
            return false;
        }

        return AiExecution::where('domain', 'laboratory')->whereIn('feature', ['laboratory_preparation_summary', 'laboratory_preparation_redactor'])
            ->where('subject_type', $purchase->getMorphClass())->where('subject_id', $purchase->id)->latest('id')->first()?->status === AiExecution::STATUS_FAILED;
    }

    private function automaticKey(LaboratoryPurchase $purchase): string
    {
        return 'laboratory_purchase:'.$purchase->id.':confirmation';
    }

    private function maxAttempts(): int
    {
        return max(1, min(5, (int) config('laboratory-confirmation.max_attempts', 3)));
    }

    private function leaseSeconds(): int
    {
        return max(120, (int) config('laboratory-confirmation.lease_seconds', 120));
    }

    private function log(string $code, int $purchaseId, ?int $confirmationId = null): void
    {
        Log::warning('laboratory_confirmation_'.$code, ['purchase_id' => $purchaseId, 'confirmation_id' => $confirmationId]);
    }
}

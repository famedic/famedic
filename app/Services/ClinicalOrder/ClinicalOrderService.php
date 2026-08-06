<?php

namespace App\Services\ClinicalOrder;

use App\Enums\ClinicalOrderStatus;
use App\Models\ClinicalOrder;
use App\Models\User;
use App\Services\CommercialIntegration\CommercialIntegrationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Builds and persists the official Clinical Order entity.
 * Cart/quote payloads are derived from the order — never the other way around.
 */
class ClinicalOrderService
{
    public function __construct(
        private CommercialIntegrationEngine $commercialEngine,
    ) {}

    /**
     * @param  array{
     *   session_id?: string|null,
     *   document?: array<string, mixed>|null,
     *   interpretation?: array<string, mixed>|null,
     *   metrics?: array<string, mixed>|null,
     *   validation_items?: list<array<string, mixed>>,
     *   status?: string|null
     * }  $context
     */
    public function build(User $operator, array $context): ClinicalOrder
    {
        $items = $context['validation_items'] ?? [];
        $proposal = $this->commercialEngine->buildProposal(
            $items,
            $context['session_id'] ?? null
        );

        $interpretation = $context['interpretation'] ?? [];
        $metrics = $context['metrics'] ?? [];
        $document = $this->sanitizeDocument(
            $context['document'] ?? ($interpretation['document'] ?? [])
        );

        $studies = $proposal['groups']['laboratories'] ?? [];
        $medications = array_map(function (array $med) {
            return [
                'name' => $med['name'] ?? null,
                'presentation' => $med['sku'] ?? null,
                'quantity' => 1,
                'status' => ($med['placeholder'] ?? false) ? 'pending_pharmacy' : 'confirmed',
                'detected_name' => $med['detected_name'] ?? null,
                'placeholder' => $med['placeholder'] ?? false,
            ];
        }, $proposal['groups']['pharmacy'] ?? []);

        $corrections = collect($items)
            ->filter(fn ($i) => ($i['validation_status'] ?? null) === 'corrected')
            ->map(fn ($i) => [
                'detected' => $i['detected_name'] ?? null,
                'confirmed' => $i['match']['name'] ?? null,
                'catalog_id' => $i['selected_catalog_id'] ?? null,
                'type' => $i['type'] ?? null,
            ])
            ->values()
            ->all();

        $confidence = $this->resolveConfidence($interpretation, $metrics);

        $status = ClinicalOrderStatus::tryFrom((string) ($context['status'] ?? ''))
            ?? ClinicalOrderStatus::Validated;

        $order = new ClinicalOrder([
            'user_id' => $operator->id,
            'session_id' => $context['session_id'] ?? ($interpretation['session_id'] ?? null),
            'status' => $status,
            'document' => $document,
            'interpretation' => [
                'prompt_version' => $metrics['prompt_version'] ?? null,
                'prompt_key' => $metrics['prompt_key'] ?? null,
                'model' => $metrics['model'] ?? null,
                'ai_json' => $interpretation['ai_json'] ?? null,
                'raw_metrics' => [
                    'duration_ms' => $metrics['duration_ms'] ?? null,
                    'prompt_tokens' => $metrics['prompt_tokens'] ?? null,
                    'completion_tokens' => $metrics['completion_tokens'] ?? null,
                    'estimated_cost_usd' => $metrics['estimated_cost_usd'] ?? null,
                ],
            ],
            'clinical_summary' => $this->buildClinicalSummary($interpretation, $studies),
            'patient' => [
                'name' => $interpretation['patient']['name'] ?? null,
                'sex' => $interpretation['patient']['sex'] ?? null,
                'age' => $interpretation['patient']['age'] ?? null,
                'observations' => $interpretation['observations']['value'] ?? null,
            ],
            'studies' => array_map(fn ($s) => [
                'code' => $s['code'] ?? null,
                'name' => $s['name'] ?? null,
                'price' => $s['price'] ?? null,
                'price_cents' => $s['price_cents'] ?? 0,
                'laboratory' => $s['laboratory'] ?? null,
                'status' => 'confirmed',
                'laboratory_test_id' => $s['laboratory_test_id'] ?? null,
                'requires_appointment' => $s['requires_appointment'] ?? false,
                'delivery_time' => $s['delivery_time'] ?? null,
                'detected_name' => $s['detected_name'] ?? null,
            ], $studies),
            'medications' => $medications,
            'validation' => [
                'operator_id' => $operator->id,
                'operator_name' => $operator->full_name ?? $operator->name ?? null,
                'validated_at' => now()->toIso8601String(),
                'corrections' => $corrections,
                'confidence' => $confidence,
                'items' => $items,
            ],
            'commercial' => [
                'summary' => $proposal['summary'] ?? [],
                'subtotal_laboratories_cents' => collect($studies)->sum('price_cents'),
                'subtotal_pharmacy_cents' => 0,
                'discounts_cents' => $proposal['summary']['discounts_cents'] ?? 0,
                'packages_applied' => $proposal['packages'] ?? [],
                'total_cents' => $proposal['summary']['total_cents'] ?? 0,
            ],
            'packages' => $proposal['packages'] ?? [],
            'cart_payload' => $proposal['cart_payload'] ?? [],
            'quote_payload' => $proposal['quote_payload'] ?? [],
            'integrations' => [
                'timeline' => ['ready' => true, 'linked' => false],
                'crm' => ['ready' => true, 'linked' => false],
                'marketing_intelligence' => ['ready' => true, 'linked' => false],
                'analytics' => ['ready' => true, 'linked' => false],
                'orders' => ['ready' => false, 'linked' => false],
                'clinical_history' => ['ready' => true, 'linked' => false],
            ],
            'confidence' => $confidence,
            'studies_count' => count($studies),
            'medications_count' => count($medications),
            'subtotal_lab_cents' => (int) collect($studies)->sum('price_cents'),
            'subtotal_pharmacy_cents' => 0,
            'discount_cents' => (int) ($proposal['summary']['discounts_cents'] ?? 0),
            'total_cents' => (int) ($proposal['summary']['total_cents'] ?? 0),
            'validated_at' => $status === ClinicalOrderStatus::Draft ? null : Carbon::now(),
        ]);

        return $order;
    }

    /**
     * Upsert by operator + session_id when possible to avoid duplicate rows.
     *
     * @param  array<string, mixed>  $context
     */
    public function save(User $operator, array $context): ClinicalOrder
    {
        $built = $this->build($operator, $context);
        $sessionId = $built->session_id;

        $existing = null;
        if (filled($sessionId)) {
            $existing = ClinicalOrder::query()
                ->where('user_id', $operator->id)
                ->where('session_id', $sessionId)
                ->whereNotIn('status', [
                    ClinicalOrderStatus::Completed->value,
                    ClinicalOrderStatus::Cancelled->value,
                ])
                ->latest('id')
                ->first();
        }

        if ($existing) {
            $next = $built->status instanceof ClinicalOrderStatus
                ? $built->status
                : ClinicalOrderStatus::tryFrom((string) $built->status);

            $current = $existing->status instanceof ClinicalOrderStatus
                ? $existing->status
                : ClinicalOrderStatus::tryFrom((string) $existing->status);

            if ($next && $current && $next !== $current && ! $current->canTransitionTo($next)) {
                // Keep payload refresh but preserve status when transition illegal.
                $built->status = $current;
            }

            $attrs = collect($built->getAttributes())
                ->except(['id', 'uuid', 'created_at', 'deleted_at'])
                ->all();

            $existing->fill($attrs);
            $existing->save();

            Log::info('clinical_interpreter.order_upserted', [
                'order_uuid' => $existing->uuid,
                'user_id' => $operator->id,
                'session_id' => $sessionId,
                'status' => $existing->status?->value ?? $existing->status,
            ]);

            return $existing->load('user');
        }

        $built->save();

        return $built->load('user');
    }

    public function findByUuid(string $uuid): ?ClinicalOrder
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            return null;
        }

        return ClinicalOrder::query()->with('user')->where('uuid', $uuid)->first();
    }

    /**
     * @deprecated Use findByUuid — numeric IDs are no longer accepted.
     */
    public function findByUuidOrId(string|int $id): ?ClinicalOrder
    {
        if (is_numeric($id)) {
            return null;
        }

        return $this->findByUuid((string) $id);
    }

    public function markQuotePrepared(ClinicalOrder $order): ClinicalOrder
    {
        return $this->transition($order, ClinicalOrderStatus::QuotePrepared);
    }

    public function markCartPrepared(ClinicalOrder $order): ClinicalOrder
    {
        return $this->transition($order, ClinicalOrderStatus::CartPrepared);
    }

    public function markCheckoutStarted(ClinicalOrder $order): ClinicalOrder
    {
        return $this->transition($order, ClinicalOrderStatus::CheckoutStarted);
    }

    public function markCompleted(ClinicalOrder $order): ClinicalOrder
    {
        return $this->transition($order, ClinicalOrderStatus::Completed);
    }

    /**
     * @param  array{id: string, label: string, meta?: array<string, mixed>|null}  $event
     */
    public function appendTimelineEvent(ClinicalOrder $order, array $event): ClinicalOrder
    {
        $integrations = is_array($order->integrations) ? $order->integrations : [];
        $timeline = is_array($integrations['timeline'] ?? null)
            ? $integrations['timeline']
            : ['ready' => true, 'linked' => false, 'events' => []];

        $events = is_array($timeline['events'] ?? null) ? $timeline['events'] : [];
        $events = array_values(array_filter(
            $events,
            fn ($existing) => ($existing['id'] ?? null) !== $event['id']
        ));
        $events[] = [
            'id' => $event['id'],
            'label' => $event['label'],
            'at' => now()->toIso8601String(),
            'meta' => $event['meta'] ?? null,
        ];

        $timeline['events'] = $events;
        $timeline['ready'] = true;
        $integrations['timeline'] = $timeline;
        $order->integrations = $integrations;
        $order->save();

        return $order->fresh('user') ?? $order;
    }

    /**
     * Complete order after real laboratory purchase (idempotent).
     */
    public function completeFromLaboratoryPurchase(
        ClinicalOrder $order,
        int $purchaseId,
    ): ClinicalOrder {
        $integrations = is_array($order->integrations) ? $order->integrations : [];
        $checkout = is_array($integrations['checkout'] ?? null) ? $integrations['checkout'] : [];
        $checkout['purchase_id'] = $purchaseId;
        $checkout['paid_at'] = now()->toIso8601String();
        $integrations['checkout'] = $checkout;
        $integrations['orders'] = ['ready' => true, 'linked' => true];
        $order->integrations = $integrations;
        $order->save();

        $order = $this->appendTimelineEvent($order, [
            'id' => 'payment_completed',
            'label' => 'Pago completado',
            'meta' => ['purchase_id' => $purchaseId],
        ]);

        $status = $order->status instanceof ClinicalOrderStatus
            ? $order->status
            : ClinicalOrderStatus::tryFrom((string) $order->status);

        if ($status === ClinicalOrderStatus::Completed) {
            return $order;
        }

        try {
            if ($status === ClinicalOrderStatus::CartPrepared) {
                $order = $this->markCheckoutStarted($order);
                $status = ClinicalOrderStatus::CheckoutStarted;
            }

            if ($status === ClinicalOrderStatus::CheckoutStarted) {
                return $this->markCompleted($order);
            }
        } catch (\InvalidArgumentException $e) {
            Log::warning('clinical_interpreter.complete_transition_skipped', [
                'order_uuid' => $order->uuid,
                'message' => $e->getMessage(),
            ]);
        }

        return $order->fresh('user') ?? $order;
    }

    private function transition(ClinicalOrder $order, ClinicalOrderStatus $next): ClinicalOrder
    {
        $current = $order->status instanceof ClinicalOrderStatus
            ? $order->status
            : ClinicalOrderStatus::tryFrom((string) $order->status);

        if (! $current) {
            throw new \InvalidArgumentException('Estado de Clinical Order inválido.');
        }

        if ($current === $next) {
            return $order->fresh('user') ?? $order;
        }

        if (! $current->canTransitionTo($next)) {
            throw new \InvalidArgumentException(
                "No se puede pasar de {$current->label()} a {$next->label()}."
            );
        }

        $order->status = $next;
        $order->save();

        return $order->fresh('user');
    }

    /**
     * Preview without persistence (for Commercial panel after validation).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function preview(User $operator, array $context): array
    {
        $order = $this->build($operator, $context);
        $proposal = $this->commercialEngine->buildProposal(
            $context['validation_items'] ?? [],
            $context['session_id'] ?? null
        );

        return [
            'clinical_order' => $order->toDetailArray(),
            'summary' => $order->toSummaryArray(),
            'proposal' => $proposal,
        ];
    }

    /**
     * @param  array<string, mixed>|mixed  $document
     * @return array<string, mixed>
     */
    private function sanitizeDocument(mixed $document): array
    {
        if (! is_array($document)) {
            return [
                'filename' => null,
                'mime' => null,
                'pages' => 1,
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        return [
            'filename' => $document['filename'] ?? null,
            'mime' => $document['mime'] ?? null,
            'pages' => $document['pages'] ?? 1,
            'uploaded_at' => $document['uploaded_at'] ?? now()->toIso8601String(),
            // Never persist data-URL / binary previews (PHI).
        ];
    }

    /**
     * @param  array<string, mixed>  $interpretation
     * @param  array<string, mixed>  $metrics
     */
    private function resolveConfidence(array $interpretation, array $metrics): ?float
    {
        $overall = $interpretation['vision_confidence']['overall']
            ?? $interpretation['ai_json']['confidence']['overall']
            ?? null;

        if (is_numeric($overall)) {
            return max(0, min(1, (float) $overall));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $interpretation
     * @param  list<array<string, mixed>>  $studies
     */
    private function buildClinicalSummary(array $interpretation, array $studies): string
    {
        $parts = [];

        if (! empty($interpretation['diagnosis']['value'])) {
            $parts[] = 'Dx: '.$interpretation['diagnosis']['value'];
        }

        if ($studies !== []) {
            $names = implode(', ', array_map(fn ($s) => $s['name'] ?? '', $studies));
            $parts[] = 'Estudios: '.$names;
        }

        if (! empty($interpretation['indications']['value'])) {
            $parts[] = 'Indicaciones: '.$interpretation['indications']['value'];
        }

        return implode(' · ', array_filter($parts)) ?: 'Sin resumen clínico disponible.';
    }
}

<?php

namespace App\Services\LaboratoryPreparation\Admin;

use App\Enums\LaboratoryBrand;
use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;
use App\Services\LaboratoryPreparation\LaboratoryPreparationRuleEngine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LaboratoryPreparationMonitorService
{
    public function __construct(
        private readonly LaboratoryPreparationPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexPayload(Request $request): array
    {
        $filters = $this->filters($request);
        $base = $this->baseQuery($filters);
        $returnFilters = array_filter([...$filters, 'page' => $request->integer('page', 1)], fn ($value) => $value !== '');

        $purchases = (clone $base)
            ->with([
                'preparationSummary:id,laboratory_purchase_id,status,decision_status,rules_version,rules_applied,fallback_reason,needs_provider_review,generated_at',
            ])
            ->orderByDesc('laboratory_purchases.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LaboratoryPurchase $purchase) => $this->row($purchase, $returnFilters));

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'metrics' => $this->metrics($base),
            'purchases' => $purchases,
            'semanticsNote' => 'Decisiones y revisión: resumen persistido. OpenAI: ejecución vinculada al resumen; sin resumen, última ejecución de preparación. Pendientes: sin resumen y ejecución en cola o proceso. Fallos técnicos incluyen ejecuciones fallidas sin resumen. Los indicadores pueden superponerse.',
            'universeNote' => 'Compras con resumen de indicaciones persistido o ejecución AI de preparación asociada. Shadow v3 no se incluye porque actualmente solo existe en logs operativos.',
            'featureFlags' => [
                'deterministic_v3_enabled' => (bool) config('services.laboratory_preparation.deterministic_v3_enabled', false),
                'deterministic_v3_shadow_enabled' => (bool) config('services.laboratory_preparation.deterministic_v3_shadow_enabled', false),
                'shadow_ui_available' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(LaboratoryPurchase $purchase, ?Request $request = null): array
    {
        $purchase->loadMissing([
            'preparationSummary.aiExecution',
            'laboratoryPurchaseItems',
        ]);

        $summary = $purchase->preparationSummary;
        $aiExecution = $summary !== null ? $summary->aiExecution : $this->latestPreparationExecution($purchase);
        if ($aiExecution && (
            $aiExecution->domain !== 'laboratory'
            || ! in_array($aiExecution->feature, ['laboratory_preparation_summary', 'laboratory_preparation_redactor'], true)
            || $aiExecution->subject_type !== $purchase->getMorphClass()
            || (int) $aiExecution->subject_id !== (int) $purchase->id
        )) {
            $aiExecution = null;
        }
        $patientPresentation = $this->presenter->present($purchase);

        return [
            'purchase' => $this->purchasePayload($purchase),
            'back_url' => route('admin.laboratory-preparation.monitor.index', $this->returnQuery($request), false),
            'history' => $this->executionHistory($purchase),
            'diagnostic' => $this->diagnosticPayload($summary),
            'openAi' => $this->openAiPayload($summary, $aiExecution),
            'indications' => [
                'original' => $purchase->laboratoryPurchaseItems
                    ->sortBy('id')
                    ->values()
                    ->map(fn ($item) => [
                        'id' => (int) $item->id,
                        'study_id' => filled($item->gda_id) ? (string) $item->gda_id : (string) $item->id,
                        'study_name' => (string) $item->name,
                        'indications' => filled($item->indications) ? (string) $item->indications : null,
                    ])
                    ->all(),
                'consolidated_requirements' => $this->safeSummaryJson($summary)['decision']['consolidated_requirements'] ?? null,
                'summary_text' => $summary?->summary_text,
                'summary_json' => $this->safeSummaryJson($summary),
                'patient_presenter' => [
                    'has_ai_summary' => $patientPresentation['has_ai_summary'] ?? false,
                    'ai_status' => $patientPresentation['ai_status'] ?? null,
                    'source' => $patientPresentation['source'] ?? null,
                    'summary' => $patientPresentation['summary'] ?? null,
                    'studies' => $patientPresentation['studies'] ?? [],
                ],
                'flag_notice' => $this->flagNotice($summary, $patientPresentation),
            ],
            'labels' => $this->labels(),
            'featureFlags' => [
                'deterministic_v3_enabled' => (bool) config('services.laboratory_preparation.deterministic_v3_enabled', false),
                'deterministic_v3_shadow_enabled' => (bool) config('services.laboratory_preparation.deterministic_v3_shadow_enabled', false),
                'shadow_ui_available' => false,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'brand' => ['nullable', 'string', 'in:'.implode(',', array_column($this->filterOptions()['brands'], 'value'))],
            'technical_status' => ['nullable', 'string', 'in:generated,stale,queued,processing,failed,succeeded_without_summary,without_summary'],
            'decision_status' => ['nullable', 'string', 'in:AUTO_CONSOLIDATED,FALLBACK_ORIGINAL'],
            'fallback_reason' => ['nullable', 'string', 'max:200'],
            'fallback_category' => ['nullable', 'string', 'in:clinical_conflict,source_quality,functional_ambiguity,technical_ai_failure'],
            'openai_status' => ['nullable', 'string', 'in:queued,processing,succeeded,failed,not_run'],
            'needs_provider_review' => ['nullable', 'string', 'in:true,false'],
            'rule' => ['nullable', 'string', 'regex:/^R(0[1-9]|1[0-8])$/'],
            'mode' => ['nullable', 'string', 'in:v3,legacy,pending'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'search' => trim((string) $request->query('search', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'brand' => trim((string) $request->query('brand', '')),
            'technical_status' => trim((string) $request->query('technical_status', '')),
            'decision_status' => trim((string) $request->query('decision_status', '')),
            'fallback_reason' => trim((string) $request->query('fallback_reason', '')),
            'fallback_category' => trim((string) $request->query('fallback_category', '')),
            'openai_status' => trim((string) $request->query('openai_status', '')),
            'needs_provider_review' => trim((string) $request->query('needs_provider_review', '')),
            'rule' => trim((string) $request->query('rule', '')),
            'mode' => trim((string) $request->query('mode', '')),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $query = LaboratoryPurchase::query()
            ->withTrashed()
            ->select('laboratory_purchases.*')
            ->leftJoin('laboratory_purchase_preparation_summaries as prep', 'prep.laboratory_purchase_id', '=', 'laboratory_purchases.id')
            ->leftJoinSub(
                AiExecution::query()->select('subject_id')->selectRaw('MAX(id) as execution_id')
                    ->where('subject_type', (new LaboratoryPurchase)->getMorphClass())
                    ->where('domain', 'laboratory')
                    ->whereIn('feature', ['laboratory_preparation_summary', 'laboratory_preparation_redactor'])
                    ->groupBy('subject_id'),
                'latest_prep', 'latest_prep.subject_id', '=', 'laboratory_purchases.id',
            )
            ->leftJoin('ai_executions as ai', function ($join) {
                $join->on('ai.id', '=', DB::raw('CASE WHEN prep.id IS NULL THEN latest_prep.execution_id ELSE prep.ai_execution_id END'))
                    ->where('ai.domain', 'laboratory')
                    ->whereIn('ai.feature', ['laboratory_preparation_summary', 'laboratory_preparation_redactor'])
                    ->where('ai.subject_type', (new LaboratoryPurchase)->getMorphClass())
                    ->whereColumn('ai.subject_id', 'laboratory_purchases.id');
            })
            ->addSelect(['ai.id as monitor_execution_id', 'ai.status as monitor_execution_status', 'ai.feature as monitor_execution_feature'])
            ->where(function (Builder $query) {
                $query->whereNotNull('prep.id')
                    ->orWhereExists(function ($subquery) {
                        $subquery->selectRaw('1')
                            ->from('ai_executions as pending_ai')
                            ->whereColumn('pending_ai.subject_id', 'laboratory_purchases.id')
                            ->where('pending_ai.subject_type', (new LaboratoryPurchase)->getMorphClass())
                            ->where('pending_ai.domain', 'laboratory')
                            ->whereIn('pending_ai.feature', [
                                'laboratory_preparation_summary',
                                'laboratory_preparation_redactor',
                            ]);
                    });
            });

        $query
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $search = $filters['search'];
                $query->where(function (Builder $query) use ($search) {
                    $query->where('laboratory_purchases.id', $search)
                        ->orWhere('laboratory_purchases.gda_order_id', 'like', "%{$search}%")
                        ->orWhere('laboratory_purchases.gda_consecutivo', 'like', "%{$search}%")
                        ->orWhereHas('laboratoryPurchaseItems', fn (Builder $items) => $items
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('gda_id', 'like', "%{$search}%"));
                });
            })
            ->when($filters['date_from'] !== '', fn (Builder $query) => $query
                ->where('laboratory_purchases.created_at', '>=', Carbon::parse($filters['date_from'], 'America/Mexico_City')->startOfDay()->utc()))
            ->when($filters['date_to'] !== '', fn (Builder $query) => $query
                ->where('laboratory_purchases.created_at', '<=', Carbon::parse($filters['date_to'], 'America/Mexico_City')->endOfDay()->utc()))
            ->when($filters['brand'] !== '', fn (Builder $query) => $query->where('laboratory_purchases.brand', $filters['brand']))
            ->when($filters['technical_status'] !== '', fn (Builder $query) => $query->whereRaw($this->technicalStatusSql().' = ?', [$filters['technical_status']]))
            ->when($filters['decision_status'] !== '', fn (Builder $query) => $query->where('prep.decision_status', $filters['decision_status']))
            ->when($filters['fallback_reason'] !== '', fn (Builder $query) => $query->where('prep.fallback_reason', $filters['fallback_reason']))
            ->when($filters['fallback_category'] !== '', fn (Builder $query) => $query->where('prep.fallback_category', $filters['fallback_category']))
            ->when($filters['openai_status'] !== '', function (Builder $query) use ($filters) {
                if ($filters['openai_status'] === 'not_run') {
                    $this->whereNoPreparationExecution($query);
                } else {
                    $this->wherePreparationExecutionStatus($query, $filters['openai_status']);
                }
            })
            ->when($filters['needs_provider_review'] !== '', fn (Builder $query) => $query
                ->where('prep.needs_provider_review', $filters['needs_provider_review'] === 'true'))
            ->when($filters['rule'] !== '', function (Builder $query) use ($filters) {
                $query->whereJsonContains('prep.rules_applied', $filters['rule']);
            })
            ->when($filters['mode'] !== '', function (Builder $query) use ($filters) {
                if ($filters['mode'] === 'v3') {
                    $query->where('prep.rules_version', LaboratoryPreparationRuleEngine::RULES_VERSION);
                } elseif ($filters['mode'] === 'legacy') {
                    $query->where(function (Builder $query) {
                        $query->whereNotNull('prep.id')
                            ->where(function (Builder $query) {
                                $query->whereNull('prep.rules_version')
                                    ->orWhere('prep.rules_version', '!=', LaboratoryPreparationRuleEngine::RULES_VERSION);
                            });
                    });
                } elseif ($filters['mode'] === 'pending') {
                    $query->whereNull('prep.id');
                }
            });

        return $query;
    }

    private function whereNoPreparationExecution(Builder $query): void
    {
        $query->whereNull('ai.id');
    }

    private function wherePreparationExecutionStatus(Builder $query, string $status): void
    {
        $query->where('ai.status', $status);
    }

    private function technicalStatusSql(): string
    {
        return "CASE WHEN prep.id IS NOT NULL THEN prep.status WHEN ai.status IN ('queued', 'processing', 'failed') THEN ai.status WHEN ai.status = 'succeeded' THEN 'succeeded_without_summary' ELSE 'without_summary' END";
    }

    private function metrics(Builder $base): array
    {
        // One aggregate over exactly the filtered purchase universe; no PHP ID list.
        $conditions = [
            'auto' => "prep.decision_status = 'AUTO_CONSOLIDATED'",
            'fallback' => "prep.decision_status = 'FALLBACK_ORIGINAL'",
            'clinical' => "prep.fallback_category = 'clinical_conflict'",
            'source_quality' => "prep.fallback_category = 'source_quality'",
            'technical' => "(prep.fallback_category = 'technical_ai_failure' OR ai.status = 'failed')",
            'pending' => "(prep.id IS NULL AND ai.status IN ('queued', 'processing'))",
            'review' => 'prep.needs_provider_review = 1',
            'openai_ok' => "ai.status = 'succeeded'",
            'openai_failed' => "ai.status = 'failed'",
        ];
        $select = ['COUNT(DISTINCT laboratory_purchases.id) as total'];
        foreach ($conditions as $key => $condition) {
            $select[] = "COUNT(DISTINCT CASE WHEN {$condition} THEN laboratory_purchases.id END) as {$key}";
        }
        $counts = (clone $base)->reorder()->select([])->selectRaw(implode(', ', $select))->toBase()->first();
        $definitions = [
            ['total', 'Compras monitoreadas', 'default'], ['auto', 'AUTO_CONSOLIDATED', 'success'],
            ['fallback', 'FALLBACK_ORIGINAL', 'warning'], ['clinical', 'Fallback clínico', 'danger'],
            ['source_quality', 'Calidad de fuente', 'warning'], ['technical', 'Fallos técnicos', 'danger'],
            ['pending', 'Pendientes', 'default'], ['review', 'Requieren revisión', 'warning'],
            ['openai_ok', 'OpenAI exitoso', 'success'], ['openai_failed', 'OpenAI fallido', 'danger'],
        ];
        $metrics = array_map(fn ($metric) => [
            'id' => $metric[0], 'label' => $metric[1], 'value' => (int) $counts->{$metric[0]}, 'tone' => $metric[2],
        ], $definitions);
        $metrics[] = ['id' => 'shadow', 'label' => 'Shadow persistido', 'value' => 'N/D', 'tone' => 'default'];

        return $metrics;
    }

    private function row(LaboratoryPurchase $purchase, array $returnFilters): array
    {
        $summary = $purchase->preparationSummary;
        $execution = $purchase->monitor_execution_id === null ? null : (new AiExecution)->forceFill([
            'id' => $purchase->monitor_execution_id,
            'status' => $purchase->monitor_execution_status,
            'feature' => $purchase->monitor_execution_feature,
        ]);

        return [
            'id' => (int) $purchase->id,
            'folio' => $this->folio($purchase),
            'brand' => $this->brandLabel($purchase),
            'created_at' => $purchase->created_at?->toIso8601String(),
            'generated_at' => $summary?->generated_at?->toIso8601String(),
            'mode' => $this->mode($summary, $execution),
            'technical_status' => $summary?->status ?? (in_array($execution?->status, ['queued', 'processing', 'failed'], true) ? $execution->status : ($execution?->status === 'succeeded' ? 'succeeded_without_summary' : 'without_summary')),
            'decision_status' => $summary?->decision_status,
            'fallback_reason' => $summary?->fallback_reason,
            'rules_applied' => $summary?->rules_applied ?? [],
            'openai_status' => $execution?->status ?? 'not_run',
            'needs_provider_review' => (bool) ($summary?->needs_provider_review ?? false),
            'deleted_at' => $purchase->deleted_at?->toIso8601String(),
            'detail_url' => route('admin.laboratory-preparation.monitor.show', ['laboratory_purchase' => $purchase->id, 'return' => $returnFilters], false),
            'admin_purchase_url' => $purchase->trashed() ? null : route('admin.laboratory-purchases.show', $purchase, false),
        ];
    }

    private function purchasePayload(LaboratoryPurchase $purchase): array
    {
        return [
            'id' => (int) $purchase->id,
            'folio' => $this->folio($purchase),
            'brand' => $this->brandLabel($purchase),
            'created_at' => $purchase->created_at?->toIso8601String(),
            'deleted_at' => $purchase->deleted_at?->toIso8601String(),
            'admin_url' => $purchase->trashed() ? null : route('admin.laboratory-purchases.show', $purchase, false),
        ];
    }

    private function diagnosticPayload(?LaboratoryPurchasePreparationSummary $summary): array
    {
        return [
            'exists' => $summary !== null,
            'decision_status' => $summary?->decision_status,
            'rules_version' => $summary?->rules_version,
            'rules_applied' => $summary?->rules_applied ?? [],
            'fallback_reason' => $summary?->fallback_reason,
            'fallback_category' => $summary?->fallback_category,
            'needs_provider_review' => $summary?->needs_provider_review,
            'source_hash' => $summary?->source_hash,
            'source_hash_short' => $summary?->source_hash ? Str::limit($summary->source_hash, 12, '') : null,
            'technical_status' => $summary?->status,
            'generated_at' => $summary?->generated_at?->toIso8601String(),
            'invalidated_at' => $summary?->invalidated_at?->toIso8601String(),
            'mode' => $this->mode($summary, $summary?->aiExecution),
        ];
    }

    private function openAiPayload(?LaboratoryPurchasePreparationSummary $summary, ?AiExecution $execution): array
    {
        $reason = null;
        if ($summary?->isFallbackOriginal() && $execution === null) {
            $reason = 'No ejecutado porque el motor eligió FALLBACK_ORIGINAL.';
        } elseif ($summary === null && $execution === null) {
            $reason = 'No hay resumen ni ejecución asociada.';
        } elseif ($execution?->status === AiExecution::STATUS_QUEUED || $execution?->status === AiExecution::STATUS_PROCESSING) {
            $reason = 'Job o ejecución en proceso.';
        } elseif ($execution?->status === AiExecution::STATUS_FAILED) {
            $reason = 'Ejecución fallida.';
        } elseif ($execution?->status === AiExecution::STATUS_SUCCEEDED) {
            $reason = 'Redacción ejecutada y persistida.';
        }

        return [
            // Queued is not a provider call. Processing/failed cannot prove a network request.
            'called' => $execution?->status === AiExecution::STATUS_SUCCEEDED ? true : ($execution === null || $execution->status === AiExecution::STATUS_QUEUED ? false : null),
            'call_evidence' => match ($execution?->status) {
                AiExecution::STATUS_SUCCEEDED => 'Respuesta del proveedor registrada.',
                AiExecution::STATUS_QUEUED => 'En cola; no acredita una llamada al proveedor.',
                AiExecution::STATUS_PROCESSING, AiExecution::STATUS_FAILED => 'El estado no permite confirmar si hubo una llamada al proveedor.',
                default => 'Sin ejecución relevante.',
            },
            'reason' => $reason,
            'ai_execution_id' => $execution?->id,
            'status' => $execution?->status ?? 'not_run',
            'model' => $execution?->model,
            'prompt_version' => $execution?->prompt_version,
            'duration_ms' => $execution?->duration_ms,
            'error' => $this->sanitizeError($execution?->error),
            'feature' => $execution?->feature,
        ];
    }

    private function latestPreparationExecution(LaboratoryPurchase $purchase): ?AiExecution
    {
        return AiExecution::query()
            ->where('domain', 'laboratory')
            ->whereIn('feature', ['laboratory_preparation_summary', 'laboratory_preparation_redactor'])
            ->where('subject_type', $purchase->getMorphClass())
            ->where('subject_id', $purchase->id)
            ->latest('id')
            ->first();
    }

    private function mode(?LaboratoryPurchasePreparationSummary $summary, ?AiExecution $execution): string
    {
        if ($summary?->rules_version === LaboratoryPreparationRuleEngine::RULES_VERSION) {
            return 'v3';
        }

        if ($summary !== null) {
            return 'legacy';
        }

        if ($execution?->feature === 'laboratory_preparation_redactor') {
            return 'v3';
        }

        if ($summary !== null || $execution !== null) {
            return 'legacy';
        }

        return 'pending';
    }

    private function folio(LaboratoryPurchase $purchase): string
    {
        return collect([
            filled($purchase->gda_order_id) ? 'GDA '.$purchase->gda_order_id : null,
            filled($purchase->gda_consecutivo) ? 'Cons. '.$purchase->gda_consecutivo : null,
        ])->filter()->implode(' / ') ?: '—';
    }

    private function brandLabel(LaboratoryPurchase $purchase): string
    {
        $brand = $purchase->brand;

        if ($brand instanceof LaboratoryBrand) {
            return $brand->label();
        }

        return filled($brand) ? (string) $brand : '—';
    }

    private function safeSummaryJson(?LaboratoryPurchasePreparationSummary $summary): ?array
    {
        if (! is_array($summary?->summary_json)) {
            return null;
        }

        return $this->sanitizeTechnicalData(array_intersect_key($summary->summary_json, array_flip([
            'summary', 'sections', 'special_instructions', 'individual_instructions',
            'decision', 'redaction', 'fallback', 'technical_failure_reason',
        ])));
    }

    private function sanitizeTechnicalData(mixed $value): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $entry) {
                if (is_string($key) && preg_match('/prompt|payload|messages?|token|secret|authorization|api.?key|password|email|phone|error/i', $key)) {
                    continue;
                }
                $safe[$key] = $this->sanitizeTechnicalData($entry);
            }

            return $safe;
        }
        if (is_string($value)) {
            return preg_replace(['/sk-[A-Za-z0-9_-]+/', '/Bearer\s+[^\s]+/i', '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i'], '[redacted]', $value);
        }

        return $value;
    }

    private function executionHistory(LaboratoryPurchase $purchase): array
    {
        $executions = AiExecution::query()
            ->where('domain', 'laboratory')
            ->whereIn('feature', ['laboratory_preparation_summary', 'laboratory_preparation_redactor'])
            ->where('subject_type', $purchase->getMorphClass())->where('subject_id', $purchase->id)
            ->latest('id')->limit(21)
            ->get(['id', 'status', 'feature', 'model', 'prompt_version', 'duration_ms', 'error', 'created_at', 'updated_at']);

        return [
            'has_more' => $executions->count() > 20,
            'executions' => $executions->take(20)->map(fn ($execution) => [
                'id' => $execution->id, 'status' => $execution->status, 'feature' => $execution->feature,
                'created_at' => $execution->created_at?->toIso8601String(),
                'updated_at' => $execution->updated_at?->toIso8601String(),
                'duration_ms' => $execution->duration_ms,
                'error' => $this->sanitizeError($execution->error),
            ])->values()->all(),
        ];
    }

    private function returnQuery(?Request $request): array
    {
        $query = $request?->query('return', []);
        if (! is_array($query)) {
            return [];
        }

        return array_filter(array_intersect_key($query, array_flip([
            ...array_keys($this->filters(new Request)), 'page',
        ])), fn ($value) => is_scalar($value) && strlen((string) $value) <= 200);
    }

    private function flagNotice(?LaboratoryPurchasePreparationSummary $summary, array $presentation): ?string
    {
        if (
            $summary?->rules_version === LaboratoryPreparationRuleEngine::RULES_VERSION
            && ! (bool) config('services.laboratory_preparation.deterministic_v3_enabled', false)
            && ($presentation['source'] ?? null) === 'fallback'
        ) {
            return 'Existe decisión v3 persistida, pero el flag determinístico está apagado; el paciente ve las indicaciones originales.';
        }

        return null;
    }

    private function sanitizeError(?string $error): ?string
    {
        if (! filled($error)) {
            return null;
        }

        // Provider exceptions can contain patient data, credentials or request bodies.
        return 'La ejecución no pudo completarse. Usa el ID de ejecución para una revisión técnica autorizada.';
    }

    private function filterOptions(): array
    {
        return [
            'brands' => collect(LaboratoryBrand::cases())
                ->map(fn (LaboratoryBrand $brand) => ['value' => $brand->value, 'label' => $brand->label()])
                ->values()
                ->all(),
            'technicalStatuses' => [
                ['value' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED, 'label' => 'Generado'],
                ['value' => LaboratoryPurchasePreparationSummary::STATUS_STALE, 'label' => 'Invalidado'],
                ['value' => 'queued', 'label' => 'En cola, sin resumen'],
                ['value' => 'processing', 'label' => 'En proceso, sin resumen'],
                ['value' => 'failed', 'label' => 'Fallo, sin resumen'],
                ['value' => 'succeeded_without_summary', 'label' => 'Ejecución exitosa sin resumen'],
            ],
            'decisionStatuses' => [
                ['value' => LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED, 'label' => 'AUTO_CONSOLIDATED'],
                ['value' => LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL, 'label' => 'FALLBACK_ORIGINAL'],
            ],
            'fallbackCategories' => [
                ['value' => LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT, 'label' => 'Conflicto clínico'],
                ['value' => LaboratoryPurchasePreparationSummary::FALLBACK_SOURCE_QUALITY, 'label' => 'Calidad de fuente'],
                ['value' => LaboratoryPurchasePreparationSummary::FALLBACK_FUNCTIONAL_AMBIGUITY, 'label' => 'Ambigüedad funcional'],
                ['value' => LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE, 'label' => 'Falla técnica IA'],
            ],
            'openAiStatuses' => [
                ['value' => AiExecution::STATUS_QUEUED, 'label' => 'Queued'],
                ['value' => AiExecution::STATUS_PROCESSING, 'label' => 'Processing'],
                ['value' => AiExecution::STATUS_SUCCEEDED, 'label' => 'Succeeded'],
                ['value' => AiExecution::STATUS_FAILED, 'label' => 'Failed'],
                ['value' => 'not_run', 'label' => 'No ejecutado'],
            ],
            'rules' => array_map(fn (int $rule) => sprintf('R%02d', $rule), range(1, 18)),
            'modes' => [
                ['value' => 'v3', 'label' => 'V3'],
                ['value' => 'legacy', 'label' => 'Legacy'],
                ['value' => 'pending', 'label' => 'Sin resumen (incluye fallos)'],
            ],
        ];
    }

    private function labels(): array
    {
        return [
            'AUTO_CONSOLIDATED' => 'Consolidado automáticamente',
            'FALLBACK_ORIGINAL' => 'Indicaciones originales',
            'clinical_conflict' => 'Conflicto clínico',
            'source_quality' => 'Calidad de fuente',
            'functional_ambiguity' => 'Ambigüedad funcional',
            'technical_ai_failure' => 'Falla técnica IA',
        ];
    }
}

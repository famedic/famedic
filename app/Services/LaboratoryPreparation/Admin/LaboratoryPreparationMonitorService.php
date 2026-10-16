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
use Illuminate\Support\Facades\Schema;
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

        $purchases = (clone $base)
            ->with([
                'preparationSummary.aiExecution',
                'laboratoryPurchaseItems:id,laboratory_purchase_id,name,gda_id,indications',
            ])
            ->orderByDesc('laboratory_purchases.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LaboratoryPurchase $purchase) => $this->row($purchase));

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'metrics' => $this->metrics($base),
            'purchases' => $purchases,
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
    public function detailPayload(LaboratoryPurchase $purchase): array
    {
        $purchase->loadMissing([
            'preparationSummary.aiExecution',
            'laboratoryPurchaseItems',
        ]);

        $summary = $purchase->preparationSummary;
        $aiExecution = $summary?->aiExecution ?? $this->latestPreparationExecution($purchase);
        $patientPresentation = $this->presenter->present($purchase);

        return [
            'purchase' => $this->purchasePayload($purchase),
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
                'consolidated_requirements' => $summary?->summary_json['decision']['consolidated_requirements'] ?? null,
                'summary_text' => $summary?->summary_text,
                'summary_json' => $this->safeSummaryJson($summary),
                'patient_presenter' => [
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
            ->leftJoin('ai_executions as ai', 'ai.id', '=', 'prep.ai_execution_id')
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
            ->when($filters['technical_status'] !== '', fn (Builder $query) => $query->where('prep.status', $filters['technical_status']))
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
                    $query->whereNotNull('prep.id')
                        ->where(function (Builder $query) {
                            $query->whereNull('prep.rules_version')
                                ->orWhere('prep.rules_version', '!=', LaboratoryPreparationRuleEngine::RULES_VERSION);
                        });
                } elseif ($filters['mode'] === 'pending') {
                    $query->whereNull('prep.id');
                }
            });

        return $query;
    }

    private function whereNoPreparationExecution(Builder $query): void
    {
        $query->whereNull('prep.ai_execution_id')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('ai_executions as filtered_ai')
                    ->whereColumn('filtered_ai.subject_id', 'laboratory_purchases.id')
                    ->where('filtered_ai.subject_type', (new LaboratoryPurchase)->getMorphClass())
                    ->where('filtered_ai.domain', 'laboratory')
                    ->whereIn('filtered_ai.feature', [
                        'laboratory_preparation_summary',
                        'laboratory_preparation_redactor',
                    ]);
            });
    }

    private function wherePreparationExecutionStatus(Builder $query, string $status): void
    {
        $query->where(function (Builder $query) use ($status) {
            $query->where('ai.status', $status)
                ->orWhereExists(function ($subquery) use ($status) {
                    $subquery->selectRaw('1')
                        ->from('ai_executions as filtered_ai')
                        ->whereColumn('filtered_ai.subject_id', 'laboratory_purchases.id')
                        ->where('filtered_ai.subject_type', (new LaboratoryPurchase)->getMorphClass())
                        ->where('filtered_ai.domain', 'laboratory')
                        ->whereIn('filtered_ai.feature', [
                            'laboratory_preparation_summary',
                            'laboratory_preparation_redactor',
                        ])
                        ->where('filtered_ai.status', $status);
                });
        });
    }

    private function metrics(Builder $base): array
    {
        $summaryIds = (clone $base)
            ->whereNotNull('prep.id')
            ->select('prep.id')
            ->distinct()
            ->pluck('prep.id');

        $query = LaboratoryPurchasePreparationSummary::query()
            ->whereIn('id', $summaryIds);

        $technicalFailures = (clone $query)
            ->where(function (Builder $query) {
                $query->where('fallback_category', LaboratoryPurchasePreparationSummary::FALLBACK_TECHNICAL_AI_FAILURE)
                    ->orWhereHas('aiExecution', fn (Builder $ai) => $ai->where('status', AiExecution::STATUS_FAILED));
            })
            ->count();

        return [
            ['id' => 'total', 'label' => 'Compras monitoreadas', 'value' => (clone $base)->distinct('laboratory_purchases.id')->count('laboratory_purchases.id'), 'tone' => 'default'],
            ['id' => 'auto', 'label' => 'AUTO_CONSOLIDATED', 'value' => (clone $query)->where('decision_status', LaboratoryPurchasePreparationSummary::DECISION_AUTO_CONSOLIDATED)->count(), 'tone' => 'success'],
            ['id' => 'fallback', 'label' => 'FALLBACK_ORIGINAL', 'value' => (clone $query)->where('decision_status', LaboratoryPurchasePreparationSummary::DECISION_FALLBACK_ORIGINAL)->count(), 'tone' => 'warning'],
            ['id' => 'clinical', 'label' => 'Fallback clínico', 'value' => (clone $query)->where('fallback_category', LaboratoryPurchasePreparationSummary::FALLBACK_CLINICAL_CONFLICT)->count(), 'tone' => 'danger'],
            ['id' => 'source_quality', 'label' => 'Calidad de fuente', 'value' => (clone $query)->where('fallback_category', LaboratoryPurchasePreparationSummary::FALLBACK_SOURCE_QUALITY)->count(), 'tone' => 'warning'],
            ['id' => 'technical', 'label' => 'Fallos técnicos', 'value' => $technicalFailures, 'tone' => 'danger'],
            ['id' => 'pending', 'label' => 'Pendientes', 'value' => (clone $base)->whereNull('prep.id')->distinct('laboratory_purchases.id')->count('laboratory_purchases.id'), 'tone' => 'default'],
            ['id' => 'review', 'label' => 'Requieren revisión', 'value' => (clone $query)->where('needs_provider_review', true)->count(), 'tone' => 'warning'],
            ['id' => 'openai_ok', 'label' => 'OpenAI exitoso', 'value' => (clone $query)->whereHas('aiExecution', fn (Builder $ai) => $ai->where('status', AiExecution::STATUS_SUCCEEDED))->count(), 'tone' => 'success'],
            ['id' => 'openai_failed', 'label' => 'OpenAI fallido', 'value' => (clone $query)->whereHas('aiExecution', fn (Builder $ai) => $ai->where('status', AiExecution::STATUS_FAILED))->count(), 'tone' => 'danger'],
            ['id' => 'shadow', 'label' => 'Shadow persistido', 'value' => 'N/D', 'tone' => 'default'],
        ];
    }

    private function row(LaboratoryPurchase $purchase): array
    {
        $summary = $purchase->preparationSummary;
        $execution = $summary?->aiExecution ?? $this->latestPreparationExecution($purchase);

        return [
            'id' => (int) $purchase->id,
            'folio' => $this->folio($purchase),
            'brand' => $this->brandLabel($purchase),
            'created_at' => $purchase->created_at?->toIso8601String(),
            'generated_at' => $summary?->generated_at?->toIso8601String(),
            'mode' => $this->mode($summary, $execution),
            'technical_status' => $summary?->status ?? ($execution?->status ? 'pending_execution' : 'without_summary'),
            'decision_status' => $summary?->decision_status,
            'fallback_reason' => $summary?->fallback_reason,
            'rules_applied' => $summary?->rules_applied ?? [],
            'openai_status' => $execution?->status ?? 'not_run',
            'needs_provider_review' => (bool) ($summary?->needs_provider_review ?? false),
            'detail_url' => route('admin.laboratory-preparation.monitor.show', $purchase, false),
            'admin_purchase_url' => route('admin.laboratory-purchases.show', $purchase, false),
        ];
    }

    private function purchasePayload(LaboratoryPurchase $purchase): array
    {
        return [
            'id' => (int) $purchase->id,
            'folio' => $this->folio($purchase),
            'brand' => $this->brandLabel($purchase),
            'created_at' => $purchase->created_at?->toIso8601String(),
            'admin_url' => route('admin.laboratory-purchases.show', $purchase, false),
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
            'called' => $execution !== null,
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
        if (! Schema::hasTable('ai_executions')) {
            return null;
        }

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

        return collect($summary->summary_json)
            ->except(['request_payload', 'messages', 'prompt'])
            ->all();
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

        $sanitized = preg_replace('/sk-[A-Za-z0-9_\-]+/', '[redacted]', $error) ?? $error;

        return Str::limit($sanitized, 600);
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
                ['value' => LaboratoryPurchasePreparationSummary::STATUS_STALE, 'label' => 'Stale'],
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
                ['value' => 'pending', 'label' => 'Pendiente'],
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

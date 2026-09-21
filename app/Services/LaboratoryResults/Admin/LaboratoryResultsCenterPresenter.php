<?php

namespace App\Services\LaboratoryResults\Admin;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultControlPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LaboratoryResultsCenterPresenter
{
    public const STATUS_NOT_AVAILABLE = 'NOT_AVAILABLE';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_STRUCTURED = 'STRUCTURED';

    public const STATUS_REVIEW = 'REVIEW';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        private readonly LaboratoryPurchaseResultControlPresenter $resultControlPresenter,
    ) {}

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public function filterOptions(): array
    {
        return [
            'statuses' => [
                ['value' => self::STATUS_NOT_AVAILABLE, 'label' => 'No disponible'],
                ['value' => self::STATUS_RECEIVED, 'label' => 'PDF recibido'],
                ['value' => self::STATUS_PROCESSING, 'label' => 'Procesando'],
                ['value' => self::STATUS_STRUCTURED, 'label' => 'Estructurado'],
                ['value' => self::STATUS_REVIEW, 'label' => 'Revisión'],
                ['value' => self::STATUS_PUBLISHED, 'label' => 'Publicado'],
                ['value' => self::STATUS_FAILED, 'label' => 'Error'],
            ],
            'extraction_statuses' => collect(LaboratoryResultExtractionStatus::cases())
                ->map(fn ($case) => ['value' => $case->value, 'label' => $case->value])
                ->values()
                ->all(),
            'structured_statuses' => collect(LaboratoryResultStructuredStatus::cases())
                ->map(fn ($case) => ['value' => $case->value, 'label' => $case->value])
                ->values()
                ->all(),
            'extraction_methods' => collect(LaboratoryResultExtractionMethod::cases())
                ->map(fn ($case) => ['value' => $case->value, 'label' => $case->value])
                ->values()
                ->all(),
            'ai_explanation_statuses' => [
                ['value' => 'pending', 'label' => 'pending'],
                ['value' => 'generating', 'label' => 'generating'],
                ['value' => 'ready', 'label' => 'ready'],
                ['value' => 'failed', 'label' => 'failed'],
                ['value' => 'invalid', 'label' => 'invalid'],
            ],
        ];
    }

    /**
     * @param  Collection<int, LaboratoryPurchase>  $purchases
     * @return list<array{id: string, label: string, value: int, tone: string}>
     */
    public function summary(Collection $purchases): array
    {
        $statuses = $purchases->map(fn (LaboratoryPurchase $purchase) => $this->deriveStatus($purchase));

        return [
            ['id' => 'total', 'label' => 'Total', 'value' => $purchases->count(), 'tone' => 'default'],
            ['id' => 'processing', 'label' => 'Procesando', 'value' => $statuses->filter(fn ($status) => $status === self::STATUS_PROCESSING)->count(), 'tone' => 'amber'],
            ['id' => 'review', 'label' => 'En revisión', 'value' => $statuses->filter(fn ($status) => $status === self::STATUS_REVIEW)->count(), 'tone' => 'violet'],
            ['id' => 'published', 'label' => 'Publicados', 'value' => $statuses->filter(fn ($status) => $status === self::STATUS_PUBLISHED)->count(), 'tone' => 'emerald'],
            ['id' => 'failed', 'label' => 'Con error', 'value' => $statuses->filter(fn ($status) => $status === self::STATUS_FAILED)->count(), 'tone' => 'red'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(LaboratoryPurchase $purchase): array
    {
        $latestVersion = $this->latestVersion($purchase);
        $latestReport = $this->latestReport($purchase);
        $latestEvent = $this->latestEvent($purchase);
        $errors = $this->errors($purchase);
        $aiExplanationCounts = $this->aiExplanationCounts($purchase);
        $resultControl = $this->resultControlPresenter->present($purchase)['resultControl'];

        return [
            'id' => $purchase->id,
            'folio' => $purchase->gda_order_id ?: $purchase->gda_consecutivo,
            'created_at' => $purchase->created_at?->toIso8601String(),
            'updated_at' => $this->latestUpdatedAt($purchase)?->toIso8601String(),
            'status' => $this->deriveStatus($purchase),
            'result_control' => [
                'overall_status' => $resultControl['overall_status'] ?? null,
                'label' => $resultControl['label'] ?? null,
                'message' => $resultControl['message'] ?? null,
                'counts' => $resultControl['counts'] ?? [],
            ],
            'pdf' => [
                'has_pdf' => (bool) ($latestVersion?->storage_path || $purchase->results),
                'version_id' => $latestVersion?->id,
                'classification' => $latestVersion?->classification?->value ?? $latestVersion?->classification,
                'source' => $latestVersion?->source,
            ],
            'extraction' => [
                'status' => $latestReport?->extraction_status?->value ?? $latestReport?->extraction_status,
                'method' => $latestReport?->extraction_method?->value ?? $latestReport?->extraction_method,
                'confidence' => $latestReport?->confidence_overall,
            ],
            'observations_count' => $purchase->resultReports->sum(fn (LaboratoryResultReport $report) => (int) $report->observation_count),
            'validation' => [
                'has_errors' => $errors !== [],
                'error_count' => count($errors),
            ],
            'publication' => [
                'status' => $latestReport?->structured_status?->value ?? $latestReport?->structured_status,
                'published_at' => $latestReport?->published_at?->toIso8601String(),
                'is_published' => $this->hasPublishedReport($purchase),
            ],
            'vision' => $this->visionSummary($purchase),
            'ai_explanation' => $aiExplanationCounts,
            'last_event' => $latestEvent ? [
                'type' => $latestEvent->event_type?->value ?? $latestEvent->event_type,
                'created_at' => $latestEvent->created_at?->toIso8601String(),
            ] : null,
            'href' => route('admin.laboratory-results-center.show', $purchase),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(LaboratoryPurchase $purchase): array
    {
        return [
            'purchase' => [
                'id' => $purchase->id,
                'folio' => $purchase->gda_order_id ?: $purchase->gda_consecutivo,
                'created_at' => $purchase->created_at?->toIso8601String(),
                'updated_at' => $purchase->updated_at?->toIso8601String(),
                'status' => $this->deriveStatus($purchase),
            ],
            'pipeline' => $this->pipeline($purchase),
            'errors' => $this->errors($purchase),
            'ai' => $this->aiExecutions($purchase),
            'events' => $this->events($purchase),
        ];
    }

    public function deriveStatus(LaboratoryPurchase $purchase): string
    {
        if ($this->hasFailure($purchase)) {
            return self::STATUS_FAILED;
        }

        if ($this->hasPublishedReport($purchase)) {
            return self::STATUS_PUBLISHED;
        }

        if ($this->needsReview($purchase)) {
            return self::STATUS_REVIEW;
        }

        if ($this->isProcessing($purchase)) {
            return self::STATUS_PROCESSING;
        }

        if ($this->hasStructuredReport($purchase)) {
            return self::STATUS_STRUCTURED;
        }

        if ($this->hasPdfVersion($purchase)) {
            return self::STATUS_RECEIVED;
        }

        return self::STATUS_NOT_AVAILABLE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pipeline(LaboratoryPurchase $purchase): array
    {
        $latestVersion = $this->latestVersion($purchase);
        $latestReport = $this->latestReport($purchase);
        $latestStatus = $this->latestResultStatus($purchase);
        $aiCounts = $this->aiExplanationCounts($purchase);

        return [
            [
                'stage' => 'PDF',
                'status' => $latestVersion || $purchase->results ? 'available' : 'missing',
                'date' => $latestVersion?->pdf_available_at?->toIso8601String() ?? $latestVersion?->created_at?->toIso8601String(),
                'attempts' => $latestStatus?->check_attempts,
                'event_type' => $this->lastEventOf($purchase, [LaboratoryResultEventType::PdfFetched, LaboratoryResultEventType::PdfChanged])?->event_type?->value,
                'details' => [
                    'storage_path' => $latestVersion ? basename((string) $latestVersion->storage_path) : ($purchase->results ? basename((string) $purchase->results) : null),
                    'classification' => $latestVersion?->classification?->value ?? $latestVersion?->classification,
                ],
            ],
            [
                'stage' => 'Result Version',
                'status' => $latestVersion ? 'ready' : 'missing',
                'date' => $latestVersion?->created_at?->toIso8601String(),
                'event_type' => $this->lastEventOf($purchase, [LaboratoryResultEventType::Classified])?->event_type?->value,
                'details' => [
                    'version_id' => $latestVersion?->id,
                    'sha256_short' => $latestVersion?->sha256 ? substr($latestVersion->sha256, 0, 12) : null,
                    'source' => $latestVersion?->source,
                ],
            ],
            [
                'stage' => 'Extraction',
                'status' => $latestReport?->extraction_status?->value ?? 'not_started',
                'date' => $latestReport?->created_at?->toIso8601String(),
                'method' => $latestReport?->extraction_method?->value ?? $latestReport?->extraction_method,
                'observations' => $latestReport?->observation_count,
                'event_type' => $this->lastEventOf($purchase, [
                    LaboratoryResultEventType::ExtractionRequested,
                    LaboratoryResultEventType::ExtractionSucceeded,
                    LaboratoryResultEventType::ExtractionPartial,
                    LaboratoryResultEventType::ExtractionFailed,
                ])?->event_type?->value,
                'execution_id' => $latestReport?->ai_execution_id,
            ],
            [
                'stage' => 'Structured Report',
                'status' => $latestReport?->structured_status?->value ?? 'missing',
                'date' => $latestReport?->updated_at?->toIso8601String(),
                'observations' => $latestReport?->observation_count,
                'details' => [
                    'report_id' => $latestReport?->id,
                    'confidence' => $latestReport?->confidence_overall,
                ],
            ],
            [
                'stage' => 'Validation',
                'status' => $latestReport && $this->validationErrors($latestReport) === [] ? 'ok' : ($latestReport ? 'issues' : 'pending'),
                'date' => $latestReport?->updated_at?->toIso8601String(),
                'errors' => $latestReport ? $this->validationErrors($latestReport) : [],
            ],
            [
                'stage' => 'Publication',
                'status' => $this->hasPublishedReport($purchase) ? 'published' : 'not_published',
                'date' => $latestReport?->published_at?->toIso8601String(),
                'event_type' => $this->lastEventOf($purchase, [
                    LaboratoryResultEventType::StructurePublished,
                    LaboratoryResultEventType::StructuredResultControlledPublished,
                ])?->event_type?->value,
            ],
            [
                'stage' => 'Vision / Shadow QA',
                'status' => $this->visionSummary($purchase)['status'],
                'date' => $this->visionSummary($purchase)['updated_at'],
                'method' => 'vision',
                'observations' => $this->visionSummary($purchase)['observations_count'],
                'execution_id' => $this->visionSummary($purchase)['ai_execution_id'],
            ],
            [
                'stage' => 'AI Explanation',
                'status' => array_sum($aiCounts['by_status']) > 0 ? 'available' : 'not_requested',
                'date' => $this->latestAiExplanationDate($purchase)?->toIso8601String(),
                'details' => $aiCounts,
            ],
        ];
    }

    private function hasFailure(LaboratoryPurchase $purchase): bool
    {
        return $purchase->laboratoryResultStatuses->contains(fn (LaboratoryResultStatus $status) => $this->enumValue($status->status) === LaboratoryResultStatusEnum::Error->value)
            || $purchase->resultReports->contains(fn (LaboratoryResultReport $report) => $this->enumValue($report->extraction_status) === LaboratoryResultExtractionStatus::Failed->value)
            || $this->events($purchase)->contains(fn (array $event) => $event['event_type'] === LaboratoryResultEventType::ExtractionFailed->value)
            || $this->aiExecutions($purchase)->contains(fn (array $execution) => $execution['status'] === 'failed')
            || $this->aiExplanationRows($purchase)->contains(fn ($row) => in_array($row['status'], ['failed', 'invalid'], true));
    }

    private function needsReview(LaboratoryPurchase $purchase): bool
    {
        return $purchase->laboratoryResultStatuses->contains(fn (LaboratoryResultStatus $status) => $this->enumValue($status->status) === LaboratoryResultStatusEnum::ManualReview->value)
            || $purchase->resultReports->contains(function (LaboratoryResultReport $report) {
                return in_array($this->enumValue($report->extraction_status), [
                    LaboratoryResultExtractionStatus::ManualReview->value,
                    LaboratoryResultExtractionStatus::Partial->value,
                ], true)
                    || $this->validationErrors($report) !== [];
            });
    }

    private function isProcessing(LaboratoryPurchase $purchase): bool
    {
        return $purchase->laboratoryResultStatuses->contains(fn (LaboratoryResultStatus $status) => $this->enumValue($status->status) === LaboratoryResultStatusEnum::AvailableUnchecked->value)
            || $purchase->resultReports->contains(fn (LaboratoryResultReport $report) => in_array($this->enumValue($report->extraction_status), [
                LaboratoryResultExtractionStatus::Pending->value,
                LaboratoryResultExtractionStatus::Processing->value,
            ], true));
    }

    private function hasStructuredReport(LaboratoryPurchase $purchase): bool
    {
        return $purchase->resultReports->contains(fn (LaboratoryResultReport $report) => (int) $report->observation_count > 0
            && in_array($this->enumValue($report->structured_status), [
                LaboratoryResultStructuredStatus::Draft->value,
                LaboratoryResultStructuredStatus::Validated->value,
            ], true));
    }

    private function hasPublishedReport(LaboratoryPurchase $purchase): bool
    {
        return $purchase->resultReports->contains(fn (LaboratoryResultReport $report) => $this->enumValue($report->structured_status) === LaboratoryResultStructuredStatus::Published->value
            && $report->published_version_slot !== null);
    }

    private function hasPdfVersion(LaboratoryPurchase $purchase): bool
    {
        return filled($purchase->results)
            || $purchase->laboratoryResultStatuses->flatMap->versions->isNotEmpty();
    }

    private function latestVersion(LaboratoryPurchase $purchase): ?LaboratoryResultVersion
    {
        return $purchase->laboratoryResultStatuses
            ->flatMap->versions
            ->sortByDesc('id')
            ->first();
    }

    private function latestReport(LaboratoryPurchase $purchase): ?LaboratoryResultReport
    {
        return $purchase->resultReports->sortByDesc('id')->first();
    }

    private function latestResultStatus(LaboratoryPurchase $purchase): ?LaboratoryResultStatus
    {
        return $purchase->laboratoryResultStatuses->sortByDesc('updated_at')->first();
    }

    private function latestEvent(LaboratoryPurchase $purchase): ?LaboratoryResultEvent
    {
        return $purchase->laboratoryResultStatuses
            ->flatMap->events
            ->sortByDesc('created_at')
            ->first();
    }

    /**
     * @param  list<LaboratoryResultEventType>  $types
     */
    private function lastEventOf(LaboratoryPurchase $purchase, array $types): ?LaboratoryResultEvent
    {
        $values = array_map(fn (LaboratoryResultEventType $type) => $type->value, $types);

        return $purchase->laboratoryResultStatuses
            ->flatMap->events
            ->filter(fn (LaboratoryResultEvent $event) => in_array($this->enumValue($event->event_type), $values, true))
            ->sortByDesc('created_at')
            ->first();
    }

    private function latestUpdatedAt(LaboratoryPurchase $purchase): ?\Carbon\CarbonInterface
    {
        return collect([
            $purchase->updated_at,
            $this->latestVersion($purchase)?->updated_at,
            $this->latestReport($purchase)?->updated_at,
            $this->latestEvent($purchase)?->created_at,
            $this->latestAiExplanationDate($purchase),
        ])->filter()->sortDesc()->first();
    }

    private function latestAiExplanationDate(LaboratoryPurchase $purchase): ?\Carbon\CarbonInterface
    {
        return $this->aiExplanationRows($purchase)
            ->pluck('updated_at_raw')
            ->filter()
            ->sortDesc()
            ->first();
    }

    /**
     * @return array{status: string, observations_count: int, report_id: ?int, ai_execution_id: ?int, updated_at: ?string}
     */
    private function visionSummary(LaboratoryPurchase $purchase): array
    {
        $visionReport = $purchase->resultReports
            ->filter(fn (LaboratoryResultReport $report) => $this->enumValue($report->extraction_method) === LaboratoryResultExtractionMethod::Vision->value)
            ->sortByDesc('id')
            ->first();

        return [
            'status' => $visionReport ? ($this->enumValue($visionReport->extraction_status) ?? 'available') : 'none',
            'observations_count' => (int) ($visionReport?->observation_count ?? 0),
            'report_id' => $visionReport?->id,
            'ai_execution_id' => $visionReport?->ai_execution_id,
            'updated_at' => $visionReport?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    private function aiExplanationCounts(LaboratoryPurchase $purchase): array
    {
        $counts = [
            'pending' => 0,
            'generating' => 0,
            'ready' => 0,
            'failed' => 0,
            'invalid' => 0,
        ];

        foreach ($this->aiExplanationRows($purchase) as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']]++;
            }
        }

        return [
            'total' => array_sum($counts),
            'by_status' => $counts,
        ];
    }

    /**
     * @return Collection<int, array{status: string, updated_at_raw: mixed}>
     */
    private function aiExplanationRows(LaboratoryPurchase $purchase): Collection
    {
        return $purchase->resultReports
            ->flatMap->observations
            ->flatMap->aiExplanations
            ->map(fn ($explanation) => [
                'status' => $this->enumValue($explanation->status),
                'updated_at_raw' => $explanation->updated_at,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function aiExecutions(LaboratoryPurchase $purchase): Collection
    {
        $executions = collect();

        foreach ($purchase->resultReports as $report) {
            if ($report->aiExecution) {
                $executions->push($this->executionPayload($report->aiExecution, 'report', $report->id));
            }

            foreach ($report->observations ?? [] as $observation) {
                foreach ($observation->aiExplanations ?? [] as $explanation) {
                    if ($explanation->aiExecution) {
                        $executions->push($this->executionPayload($explanation->aiExecution, 'ai_explanation', $explanation->id));
                    }
                }
            }
        }

        return $executions->unique('id')->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function executionPayload(AiExecution $execution, string $source, int $sourceId): array
    {
        return [
            'id' => $execution->id,
            'source' => $source,
            'source_id' => $sourceId,
            'status' => $execution->status,
            'duration_ms' => $execution->duration_ms,
            'prompt_tokens' => $execution->prompt_tokens,
            'completion_tokens' => $execution->completion_tokens,
            'total_tokens' => $execution->total_tokens,
            'estimated_cost_usd' => $execution->estimated_cost_usd,
            'error' => $this->sanitizeMessage($execution->error),
            'created_at' => $execution->created_at?->toIso8601String(),
            'updated_at' => $execution->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function events(LaboratoryPurchase $purchase): Collection
    {
        return $purchase->laboratoryResultStatuses
            ->flatMap->events
            ->sortByDesc('created_at')
            ->values()
            ->map(fn (LaboratoryResultEvent $event) => [
                'id' => $event->id,
                'event_type' => $this->enumValue($event->event_type),
                'from_status' => $this->enumValue($event->from_status),
                'to_status' => $this->enumValue($event->to_status),
                'message' => $this->sanitizeMessage($event->metadata['error_code'] ?? $event->metadata['reason'] ?? null),
                'created_at' => $event->created_at?->toIso8601String(),
                'version_id' => $event->laboratory_result_version_id,
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function errors(LaboratoryPurchase $purchase): array
    {
        $errors = [];

        foreach ($purchase->laboratoryResultStatuses as $status) {
            if ($this->enumValue($status->status) === LaboratoryResultStatusEnum::Error->value) {
                $errors[] = [
                    'stage' => 'PDF/status',
                    'message' => 'El status del resultado está en error.',
                    'date' => $status->updated_at?->toIso8601String(),
                    'attempts' => $status->check_attempts,
                    'event_type' => null,
                    'execution_id' => null,
                ];
            }

            foreach ($status->events as $event) {
                if (in_array($this->enumValue($event->event_type), [
                    LaboratoryResultEventType::ExtractionFailed->value,
                    LaboratoryResultEventType::RefreshFailed->value,
                    LaboratoryResultEventType::ClassificationFailed->value,
                    LaboratoryResultEventType::RefreshAttemptsExhausted->value,
                ], true)) {
                    $errors[] = [
                        'stage' => 'Event',
                        'message' => $this->sanitizeMessage($event->metadata['error_code'] ?? $event->metadata['reason'] ?? $this->enumValue($event->event_type)),
                        'date' => $event->created_at?->toIso8601String(),
                        'attempts' => $status->check_attempts,
                        'event_type' => $this->enumValue($event->event_type),
                        'execution_id' => $event->metadata['ai_execution_id'] ?? null,
                    ];
                }
            }
        }

        foreach ($purchase->resultReports as $report) {
            foreach ($this->validationErrors($report) as $message) {
                $errors[] = [
                    'stage' => 'Validation',
                    'message' => $message,
                    'date' => $report->updated_at?->toIso8601String(),
                    'attempts' => null,
                    'event_type' => null,
                    'execution_id' => $report->ai_execution_id,
                ];
            }

            if ($this->enumValue($report->extraction_status) === LaboratoryResultExtractionStatus::Failed->value) {
                $errors[] = [
                    'stage' => 'Extraction',
                    'message' => 'La extracción falló.',
                    'date' => $report->updated_at?->toIso8601String(),
                    'attempts' => null,
                    'event_type' => LaboratoryResultEventType::ExtractionFailed->value,
                    'execution_id' => $report->ai_execution_id,
                ];
            }
        }

        foreach ($this->aiExecutions($purchase) as $execution) {
            if ($execution['status'] === 'failed') {
                $errors[] = [
                    'stage' => 'AI execution',
                    'message' => $execution['error'] ?: 'La ejecución AI falló.',
                    'date' => $execution['updated_at'],
                    'attempts' => null,
                    'event_type' => null,
                    'execution_id' => $execution['id'],
                ];
            }
        }

        return collect($errors)
            ->filter(fn ($error) => filled($error['message']))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function validationErrors(LaboratoryResultReport $report): array
    {
        $errors = $report->validation_errors ?? [];

        if (! is_array($errors) || $errors === []) {
            return [];
        }

        return collect($errors)
            ->map(function ($error) {
                if (is_string($error)) {
                    return $this->sanitizeMessage($error);
                }

                if (is_array($error)) {
                    $code = $error['error_code'] ?? null;
                    $messages = $error['errors'] ?? null;

                    if (is_array($messages)) {
                        return $this->sanitizeMessage(trim(($code ? $code.': ' : '').implode(', ', $messages)));
                    }

                    return $this->sanitizeMessage($code ?? json_encode(array_keys($error)));
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function sanitizeMessage(mixed $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $message = Str::of((string) $message)
            ->replaceMatches('/sk-[A-Za-z0-9_\-]+/', '[redacted]')
            ->replaceMatches('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [redacted]')
            ->replaceMatches('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]')
            ->replaceMatches('/\b\d{10,16}\b/', '[number]')
            ->limit(220, '...')
            ->toString();

        return trim($message);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value !== null ? (string) $value : null;
    }
}

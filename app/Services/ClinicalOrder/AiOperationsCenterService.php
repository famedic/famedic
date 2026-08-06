<?php

namespace App\Services\ClinicalOrder;

use App\Enums\ClinicalOrderStatus;
use App\Models\ClinicalOrder;
use App\Services\ClinicalLearning\AiLearningService;
use App\Services\DocumentInterpretation\Prompts\PromptProvider;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * AI Operations Center — admin console aggregates.
 * Consumes ClinicalOrder + AiLearningService + PromptProvider only.
 * Does not modify Vision / Matching / Validation / Commercial engines.
 */
class AiOperationsCenterService
{
    public function __construct(
        private AiLearningService $learning,
        private PromptProvider $prompts,
    ) {}

    /**
     * @param  array{q?: string|null, status?: string|null, operator?: string|null, laboratory?: string|null, model?: string|null, prompt?: string|null, confidence?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $hasOrders = Schema::hasTable('clinical_orders');
        $orders = $hasOrders
            ? ClinicalOrder::query()->with('user')->latest()->limit(500)->get()
            : collect();

        $now = now();
        $today = $orders->filter(fn (ClinicalOrder $o) => $o->created_at?->isSameDay($now));
        $week = $orders->filter(fn (ClinicalOrder $o) => $o->created_at?->gte($now->copy()->startOfWeek()));

        $learningPayload = $this->learning->dashboardPayload(80);
        $corrections = collect($learningPayload['frequent_corrections'] ?? [])
            ->map(fn ($row) => is_array($row) ? $row : (array) $row)
            ->filter(fn ($row) => ($row['type'] ?? null) === 'laboratory')
            ->values();

        return [
            'meta' => [
                'product' => 'AI Operations Center',
                'scope' => 'AI Clinical Interpreter',
                'version' => '1.0',
                'generated_at' => $now->toIso8601String(),
                'truth' => $hasOrders ? 'clinical_orders + ai_learning + prompt_provider' : 'empty',
                'note' => 'Consola administrativa. No modifica motores ni el Wizard.',
            ],
            'modules' => [
                'overview' => $this->overview($orders, $today, $week),
                'prompts' => $this->promptsModule(),
                'confidence' => $this->confidenceModule($orders, $corrections),
                'learning' => $this->learningModule($corrections, $learningPayload),
                'explorer' => $this->explorerModule($orders, $filters),
                'performance' => $this->performanceModule($orders),
                'health' => $this->healthModule($orders),
                'roadmap' => $this->roadmapModule(),
            ],
        ];
    }

    /**
     * @param  Collection<int, ClinicalOrder>  $orders
     * @param  Collection<int, ClinicalOrder>  $today
     * @param  Collection<int, ClinicalOrder>  $week
     * @return array<string, mixed>
     */
    private function overview(Collection $orders, Collection $today, Collection $week): array
    {
        $durations = $orders
            ->map(fn (ClinicalOrder $o) => data_get($o->interpretation, 'raw_metrics.duration_ms'))
            ->filter(fn ($ms) => is_numeric($ms))
            ->map(fn ($ms) => (float) $ms);

        $autoRate = $this->autoMatchRate($orders);
        $humanCorrections = $orders->sum(fn (ClinicalOrder $o) => count(data_get($o->validation, 'corrections', []) ?: []));

        $interpreted = $orders->filter(fn (ClinicalOrder $o) => $this->reached($o, ClinicalOrderStatus::Interpreted))->count();
        $withOrder = $orders->filter(fn (ClinicalOrder $o) => $this->reached($o, ClinicalOrderStatus::Validated))->count();
        $checkouts = $orders->filter(fn (ClinicalOrder $o) => $this->reached($o, ClinicalOrderStatus::CheckoutStarted))->count();
        $purchases = $orders->filter(fn (ClinicalOrder $o) => $o->status === ClinicalOrderStatus::Completed)->count();

        $kpis = [
            ['id' => 'today', 'label' => 'Interpretaciones hoy', 'value' => $today->count(), 'tone' => 'default'],
            ['id' => 'week', 'label' => 'Interpretaciones esta semana', 'value' => $week->count(), 'tone' => 'default'],
            [
                'id' => 'avg_time',
                'label' => 'Tiempo promedio',
                'value' => $durations->isEmpty()
                    ? '—'
                    : round($durations->avg() / 1000, 1).' s',
                'tone' => 'default',
                'hint' => 'Desde raw_metrics de órdenes con métricas',
            ],
            [
                'id' => 'auto_match',
                'label' => 'Coincidencia automática',
                'value' => $autoRate === null ? '—' : $autoRate.'%',
                'tone' => 'green',
            ],
            [
                'id' => 'corrections',
                'label' => 'Correcciones humanas',
                'value' => $humanCorrections,
                'tone' => 'orange',
            ],
            [
                'id' => 'orders',
                'label' => 'Laboratory Orders',
                'value' => $withOrder,
                'tone' => 'default',
            ],
            [
                'id' => 'checkouts',
                'label' => 'Checkouts iniciados',
                'value' => $checkouts,
                'tone' => 'blue',
            ],
            [
                'id' => 'purchases',
                'label' => 'Compras completadas',
                'value' => $purchases,
                'tone' => 'green',
            ],
        ];

        $funnel = [
            ['id' => 'interpretation', 'label' => 'Interpretación', 'count' => max($interpreted, $orders->count())],
            ['id' => 'order', 'label' => 'Order', 'count' => $withOrder],
            ['id' => 'checkout', 'label' => 'Checkout', 'count' => $checkouts],
            ['id' => 'purchase', 'label' => 'Compra', 'count' => $purchases],
        ];

        return [
            'kpis' => $kpis,
            'funnel' => $funnel,
            'conversion' => [
                'interpretation_to_order' => $this->pct(max($interpreted, $orders->count()), $withOrder),
                'order_to_checkout' => $this->pct($withOrder, $checkouts),
                'checkout_to_purchase' => $this->pct($checkouts, $purchases),
                'interpretation_to_purchase' => $this->pct(max($interpreted, $orders->count()), $purchases),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promptsModule(): array
    {
        try {
            $active = $this->prompts->active()->toConfigArray();
            $catalog = $this->prompts->catalogForUi();
        } catch (\Throwable) {
            $active = null;
            $catalog = [];
        }

        $versions = collect($catalog)->map(function (array $prompt) use ($active) {
            $isActive = $active && ($prompt['key'] ?? null) === ($active['key'] ?? null);

            return [
                'key' => $prompt['key'] ?? null,
                'version' => $prompt['version'] ?? null,
                'label' => $prompt['label'] ?? null,
                'model' => $prompt['model'] ?? null,
                'status' => $prompt['status'] ?? null,
                'active' => $isActive,
                'author' => 'PromptProvider',
                'notes' => $isActive ? 'Prompt activo (config clinical_interpreter.active_prompt)' : 'Disponible en catálogo',
                'editable' => false,
                'system_prompt_preview' => mb_substr((string) ($prompt['system_prompt'] ?? ''), 0, 280),
                'user_prompt_preview' => mb_substr((string) ($prompt['user_prompt'] ?? ''), 0, 160),
                'system_prompt' => $prompt['system_prompt'] ?? '',
                'user_prompt' => $prompt['user_prompt'] ?? '',
            ];
        })->values()->all();

        return [
            'active' => $active ? [
                'key' => $active['key'] ?? null,
                'version' => $active['version'] ?? null,
                'label' => $active['label'] ?? null,
                'model' => $active['model'] ?? null,
                'status' => $active['status'] ?? null,
                'author' => 'PromptProvider',
                'notes' => 'Administración de solo lectura. Cambiar el activo vía env CLINICAL_INTERPRETER_PROMPT / Configuración IA.',
            ] : null,
            'versions' => $versions,
            'actions' => [
                ['id' => 'compare', 'label' => 'Comparar versiones', 'available' => count($versions) >= 1],
                ['id' => 'restore', 'label' => 'Restaurar', 'available' => false, 'note' => 'Sin escritura en PromptProvider'],
                ['id' => 'duplicate', 'label' => 'Duplicar', 'available' => false, 'note' => 'Sin escritura en PromptProvider'],
                ['id' => 'test', 'label' => 'Probar prompt', 'available' => false, 'note' => 'Usar el Asistente / Matching Engine'],
            ],
            'truth' => 'prompt_provider_readonly',
        ];
    }

    /**
     * @param  Collection<int, ClinicalOrder>  $orders
     * @param  Collection<int, array<string, mixed>>  $corrections
     * @return array<string, mixed>
     */
    private function confidenceModule(Collection $orders, Collection $corrections): array
    {
        $studyScores = [];

        foreach ($orders as $order) {
            $items = data_get($order->validation, 'items', []) ?: [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = data_get($item, 'match.name')
                    ?: data_get($item, 'detected_name')
                    ?: 'Estudio';
                $sim = data_get($item, 'match.similarity');
                if (! is_numeric($sim)) {
                    continue;
                }
                $sim = (float) $sim;
                $key = mb_strtolower(trim((string) $name));
                if (! isset($studyScores[$key])) {
                    $studyScores[$key] = ['name' => $name, 'scores' => [], 'count' => 0];
                }
                $studyScores[$key]['scores'][] = $sim;
                $studyScores[$key]['count']++;
            }

            // Fallback: order-level confidence when items lack similarity
            if ($items === [] && is_numeric($order->confidence)) {
                $pct = (float) $order->confidence;
                if ($pct > 0 && $pct <= 1) {
                    $pct *= 100;
                }
                $studyScores['_order_'.$order->id] = [
                    'name' => 'Orden #'.$order->id,
                    'scores' => [$pct],
                    'count' => 1,
                ];
            }
        }

        $averaged = collect($studyScores)
            ->map(fn ($row) => [
                'name' => $row['name'],
                'avg' => round(array_sum($row['scores']) / max(1, count($row['scores'])), 1),
                'count' => $row['count'],
            ])
            ->sortByDesc('avg')
            ->values();

        $allScores = $averaged->pluck('avg');
        $distribution = [
            ['id' => 'high', 'label' => 'Alta', 'range' => '95–100%', 'count' => $allScores->filter(fn ($v) => $v >= 95)->count()],
            ['id' => 'medium', 'label' => 'Media', 'range' => '80–94%', 'count' => $allScores->filter(fn ($v) => $v >= 80 && $v < 95)->count()],
            ['id' => 'low', 'label' => 'Baja', 'range' => '< 80%', 'count' => $allScores->filter(fn ($v) => $v < 80)->count()],
        ];

        $ambiguous = [];
        foreach ($orders as $order) {
            foreach (data_get($order->validation, 'items', []) ?: [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $alts = data_get($item, 'alternatives', []) ?: [];
                $highAlts = collect($alts)->filter(fn ($a) => is_numeric(data_get($a, 'similarity')) && (float) data_get($a, 'similarity') >= 92);
                if ($highAlts->count() >= 2 || (data_get($item, 'engine_status') === 'partial')) {
                    $ambiguous[] = [
                        'detected' => data_get($item, 'detected_name'),
                        'chosen' => data_get($item, 'match.name'),
                        'order_uuid' => $order->uuid,
                    ];
                }
            }
        }

        return [
            'highest' => $averaged->take(8)->values()->all(),
            'lowest' => $averaged->sortBy('avg')->take(8)->values()->all(),
            'ambiguous' => array_slice($ambiguous, 0, 20),
            'frequent_corrections' => $corrections->take(15)->values()->all(),
            'distribution' => $distribution,
            'chart' => $distribution,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $corrections
     * @param  array<string, mixed>  $learningPayload
     * @return array<string, mixed>
     */
    private function learningModule(Collection $corrections, array $learningPayload): array
    {
        $topCorrected = $corrections->take(15)->map(fn ($row) => [
            'detected' => $row['detected_text'] ?? null,
            'confirmed' => $row['confirmed_text'] ?? null,
            'occurrences' => $row['occurrences'] ?? 0,
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'type' => $row['type'] ?? 'laboratory',
        ])->values()->all();

        $synonyms = $corrections
            ->filter(function ($row) {
                $a = mb_strtolower(trim((string) ($row['detected_text'] ?? '')));
                $b = mb_strtolower(trim((string) ($row['confirmed_text'] ?? '')));

                return $a !== '' && $b !== '' && $a !== $b;
            })
            ->take(12)
            ->map(fn ($row) => [
                'variant' => $row['detected_text'] ?? null,
                'canonical' => $row['confirmed_text'] ?? null,
                'occurrences' => $row['occurrences'] ?? 0,
            ])
            ->values()
            ->all();

        $shortVariants = $corrections
            ->filter(fn ($row) => mb_strlen((string) ($row['detected_text'] ?? '')) <= 12)
            ->take(10)
            ->map(fn ($row) => [
                'text' => $row['detected_text'] ?? null,
                'maps_to' => $row['confirmed_text'] ?? null,
                'occurrences' => $row['occurrences'] ?? 0,
            ])
            ->values()
            ->all();

        $ranking = $corrections
            ->sortByDesc(fn ($row) => (int) ($row['occurrences'] ?? 0))
            ->take(10)
            ->values()
            ->map(fn ($row, $i) => [
                'rank' => $i + 1,
                'label' => ($row['detected_text'] ?? '—').' → '.($row['confirmed_text'] ?? '—'),
                'score' => (int) ($row['occurrences'] ?? 0),
            ])
            ->all();

        return [
            'meta' => $learningPayload['meta'] ?? ['status' => 'prepared'],
            'top_corrected' => $topCorrected,
            'new_synonyms' => $synonyms,
            'new_variants' => $shortVariants,
            'new_laboratories' => [],
            'ranking' => $ranking,
            'note' => 'Solo consume AiLearningService. No entrena modelos.',
        ];
    }

    /**
     * @param  Collection<int, ClinicalOrder>  $orders
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function explorerModule(Collection $orders, array $filters): array
    {
        $filtered = $orders->filter(function (ClinicalOrder $order) use ($filters) {
            if ($q = trim((string) ($filters['q'] ?? ''))) {
                $hay = mb_strtolower(implode(' ', [
                    (string) data_get($order->patient, 'name'),
                    (string) $order->uuid,
                    (string) $order->user?->name,
                    (string) $order->user?->email,
                ]));
                if (! str_contains($hay, mb_strtolower($q))) {
                    return false;
                }
            }

            if ($status = $filters['status'] ?? null) {
                $value = $order->status instanceof ClinicalOrderStatus
                    ? $order->status->value
                    : (string) $order->status;
                if ($value !== $status) {
                    return false;
                }
            }

            if ($operator = trim((string) ($filters['operator'] ?? ''))) {
                $opHay = mb_strtolower((string) ($order->user?->name ?? '').' '.($order->user?->email ?? ''));
                if (! str_contains($opHay, mb_strtolower($operator))) {
                    return false;
                }
            }

            if ($lab = trim((string) ($filters['laboratory'] ?? ''))) {
                $labs = collect($order->studies ?: [])
                    ->map(fn ($s) => mb_strtolower((string) ($s['laboratory'] ?? '')))
                    ->implode(' ');
                if (! str_contains($labs, mb_strtolower($lab))) {
                    return false;
                }
            }

            if ($model = trim((string) ($filters['model'] ?? ''))) {
                $m = (string) data_get($order->interpretation, 'model', '');
                if (! str_contains(mb_strtolower($m), mb_strtolower($model))) {
                    return false;
                }
            }

            if ($prompt = trim((string) ($filters['prompt'] ?? ''))) {
                $p = (string) (
                    data_get($order->interpretation, 'prompt_key')
                    ?: data_get($order->interpretation, 'prompt_version')
                    ?: ''
                );
                if (! str_contains(mb_strtolower($p), mb_strtolower($prompt))) {
                    return false;
                }
            }

            if ($band = $filters['confidence'] ?? null) {
                $c = $order->confidence;
                if (! is_numeric($c)) {
                    return false;
                }
                $pct = (float) $c;
                if ($pct > 0 && $pct <= 1) {
                    $pct *= 100;
                }
                $ok = match ($band) {
                    'high' => $pct >= 95,
                    'medium' => $pct >= 80 && $pct < 95,
                    'low' => $pct < 80,
                    default => true,
                };
                if (! $ok) {
                    return false;
                }
            }

            if ($from = $filters['from'] ?? null) {
                try {
                    if ($order->created_at?->lt(Carbon::parse($from)->startOfDay())) {
                        return false;
                    }
                } catch (\Throwable) {
                    // ignore bad date
                }
            }

            if ($to = $filters['to'] ?? null) {
                try {
                    if ($order->created_at?->gt(Carbon::parse($to)->endOfDay())) {
                        return false;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }

            return true;
        });

        $rows = $filtered->take(80)->map(function (ClinicalOrder $order) {
            $summary = $order->toSummaryArray();
            $labs = collect($order->studies ?: [])
                ->pluck('laboratory')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return [
                ...$summary,
                'patient_name' => data_get($order->patient, 'name'),
                'operator_name' => $order->user?->name ?? data_get($order->validation, 'operator_name'),
                'laboratories' => $labs,
                'model' => data_get($order->interpretation, 'model'),
                'prompt_key' => data_get($order->interpretation, 'prompt_key'),
                'prompt_version' => data_get($order->interpretation, 'prompt_version'),
                'duration_ms' => data_get($order->interpretation, 'raw_metrics.duration_ms'),
                'show_url' => route('admin.clinical-interpreter.clinical-orders.show', $order->uuid),
            ];
        })->values()->all();

        return [
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
                'operator' => $filters['operator'] ?? '',
                'laboratory' => $filters['laboratory'] ?? '',
                'model' => $filters['model'] ?? '',
                'prompt' => $filters['prompt'] ?? '',
                'confidence' => $filters['confidence'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'status_options' => collect(ClinicalOrderStatus::cases())
                ->map(fn (ClinicalOrderStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values()
                ->all(),
            'rows' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * @param  Collection<int, ClinicalOrder>  $orders
     * @return array<string, mixed>
     */
    private function performanceModule(Collection $orders): array
    {
        $visionMs = $orders
            ->map(fn (ClinicalOrder $o) => data_get($o->interpretation, 'raw_metrics.duration_ms'))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v);

        $validationMs = $orders
            ->filter(fn (ClinicalOrder $o) => $o->validated_at && $o->created_at)
            ->map(fn (ClinicalOrder $o) => max(0, $o->created_at->diffInMilliseconds($o->validated_at)))
            ->filter(fn ($v) => $v > 0 && $v < 86_400_000);

        $checkoutMs = $orders
            ->map(function (ClinicalOrder $o) {
                $prepared = data_get($o->integrations, 'checkout.prepared_at');
                if (! $prepared || ! $o->validated_at) {
                    return null;
                }
                try {
                    return max(0, $o->validated_at->diffInMilliseconds(Carbon::parse($prepared)));
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter(fn ($v) => is_numeric($v));

        $purchaseMs = $orders
            ->map(function (ClinicalOrder $o) {
                $paid = data_get($o->integrations, 'checkout.paid_at');
                $prepared = data_get($o->integrations, 'checkout.prepared_at');
                if (! $paid || ! $prepared) {
                    return null;
                }
                try {
                    return max(0, Carbon::parse($prepared)->diffInMilliseconds(Carbon::parse($paid)));
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter(fn ($v) => is_numeric($v));

        $stages = [
            [
                'id' => 'openai',
                'label' => 'Tiempo OpenAI',
                'avg_ms' => $visionMs->isEmpty() ? null : round($visionMs->avg()),
                'samples' => $visionMs->count(),
                'truth' => 'interpretation.raw_metrics.duration_ms',
            ],
            [
                'id' => 'matching',
                'label' => 'Tiempo Matching',
                'avg_ms' => null,
                'samples' => 0,
                'truth' => 'no_instrumented',
                'note' => 'Matching no persiste latencia dedicada',
            ],
            [
                'id' => 'validation',
                'label' => 'Tiempo Validación',
                'avg_ms' => $validationMs->isEmpty() ? null : round($validationMs->avg()),
                'samples' => $validationMs->count(),
                'truth' => 'created_at → validated_at',
            ],
            [
                'id' => 'checkout',
                'label' => 'Tiempo Checkout',
                'avg_ms' => $checkoutMs->isEmpty() ? null : round($checkoutMs->avg()),
                'samples' => $checkoutMs->count(),
                'truth' => 'validated_at → checkout.prepared_at',
            ],
            [
                'id' => 'purchase',
                'label' => 'Tiempo Compra',
                'avg_ms' => $purchaseMs->isEmpty() ? null : round($purchaseMs->avg()),
                'samples' => $purchaseMs->count(),
                'truth' => 'prepared_at → paid_at',
            ],
        ];

        $funnel = [
            ['id' => 'interpretation', 'label' => 'Interpretación', 'count' => $orders->count()],
            [
                'id' => 'validated',
                'label' => 'Validada',
                'count' => $orders->filter(fn (ClinicalOrder $o) => $this->reached($o, ClinicalOrderStatus::Validated))->count(),
            ],
            [
                'id' => 'checkout',
                'label' => 'Checkout',
                'count' => $orders->filter(fn (ClinicalOrder $o) => $this->reached($o, ClinicalOrderStatus::CheckoutStarted))->count(),
            ],
            [
                'id' => 'purchase',
                'label' => 'Compra',
                'count' => $orders->filter(fn (ClinicalOrder $o) => $o->status === ClinicalOrderStatus::Completed)->count(),
            ],
        ];

        return [
            'stages' => $stages,
            'funnel' => $funnel,
        ];
    }

    /**
     * @param  Collection<int, ClinicalOrder>  $orders
     * @return array<string, mixed>
     */
    private function healthModule(Collection $orders): array
    {
        $openaiKey = (bool) config('services.openai.key');
        $activePrompt = null;
        try {
            $activePrompt = $this->prompts->active()->toConfigArray();
        } catch (\Throwable) {
            // leave null
        }

        $costs = $orders
            ->map(fn (ClinicalOrder $o) => data_get($o->interpretation, 'raw_metrics.estimated_cost_usd'))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v);

        $last = $orders->first();

        return [
            'checks' => [
                [
                    'id' => 'openai',
                    'label' => 'Estado OpenAI',
                    'status' => $openaiKey ? 'ok' : 'warn',
                    'detail' => $openaiKey ? 'API key configurada' : 'API key no detectada en config',
                ],
                [
                    'id' => 'prompt',
                    'label' => 'Prompt activo',
                    'status' => $activePrompt ? 'ok' : 'error',
                    'detail' => $activePrompt
                        ? (($activePrompt['key'] ?? '—').' · v'.($activePrompt['version'] ?? '—'))
                        : 'No disponible',
                ],
                [
                    'id' => 'avg_cost',
                    'label' => 'Costo promedio',
                    'status' => $costs->isEmpty() ? 'warn' : 'ok',
                    'detail' => $costs->isEmpty()
                        ? 'Sin muestras en raw_metrics'
                        : '~$'.number_format($costs->avg(), 4).' USD',
                ],
                [
                    'id' => 'vision_errors',
                    'label' => 'Errores Vision',
                    'status' => 'info',
                    'detail' => 'Sin tabla de errores · solo logs',
                    'truth' => 'logs_only',
                ],
                [
                    'id' => 'matching_errors',
                    'label' => 'Errores Matching',
                    'status' => 'info',
                    'detail' => 'Sin tabla de errores · solo logs',
                    'truth' => 'logs_only',
                ],
                [
                    'id' => 'checkout_errors',
                    'label' => 'Errores Checkout',
                    'status' => 'info',
                    'detail' => 'Sin tabla de errores · revisar bridge en órdenes',
                    'truth' => 'logs_only',
                ],
                [
                    'id' => 'availability',
                    'label' => 'Disponibilidad',
                    'status' => Schema::hasTable('clinical_orders') ? 'ok' : 'error',
                    'detail' => Schema::hasTable('clinical_orders')
                        ? 'clinical_orders disponible'
                        : 'Tabla clinical_orders ausente',
                ],
                [
                    'id' => 'last',
                    'label' => 'Última interpretación',
                    'status' => $last ? 'ok' : 'warn',
                    'detail' => $last
                        ? ('#'.$last->id.' · '.($last->created_at?->toIso8601String() ?? '—'))
                        : 'Sin órdenes todavía',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roadmapModule(): array
    {
        return [
            'items' => [
                ['id' => 'ocr', 'title' => 'OCR alternativo', 'status' => 'planned', 'blurb' => 'Capa OCR dedicada además de Vision.'],
                ['id' => 'pharmacy', 'title' => 'Farmacia', 'status' => 'planned', 'blurb' => 'Medicamentos en el mismo flujo interpretativo.'],
                ['id' => 'imaging', 'title' => 'Imagenología', 'status' => 'planned', 'blurb' => 'Órdenes de imagen y estudios especiales.'],
                ['id' => 'specialties', 'title' => 'Especialidades', 'status' => 'planned', 'blurb' => 'Flujos por especialidad clínica.'],
                ['id' => 'multi', 'title' => 'Múltiples recetas', 'status' => 'planned', 'blurb' => 'Batch de documentos en una sesión.'],
                ['id' => 'ml', 'title' => 'Aprendizaje automático', 'status' => 'planned', 'blurb' => 'Entrenamiento real a partir de AI Learning.'],
                ['id' => 'his', 'title' => 'Integración HIS', 'status' => 'planned', 'blurb' => 'Conexión con historial clínico hospitalario.'],
            ],
            'note' => 'Capacidades futuras · no implementadas.',
        ];
    }

    private function reached(ClinicalOrder $order, ClinicalOrderStatus $min): bool
    {
        $rank = [
            ClinicalOrderStatus::Draft->value => 0,
            ClinicalOrderStatus::Interpreted->value => 1,
            ClinicalOrderStatus::Validated->value => 2,
            ClinicalOrderStatus::QuotePrepared->value => 3,
            ClinicalOrderStatus::CartPrepared->value => 4,
            ClinicalOrderStatus::CheckoutStarted->value => 5,
            ClinicalOrderStatus::Completed->value => 6,
            ClinicalOrderStatus::Cancelled->value => -1,
        ];

        $value = $order->status instanceof ClinicalOrderStatus
            ? $order->status->value
            : (string) $order->status;

        if (($rank[$value] ?? -1) < 0) {
            return false;
        }

        return ($rank[$value] ?? 0) >= ($rank[$min->value] ?? 0);
    }

    /**
     * @param  Collection<int, ClinicalOrder>  $orders
     */
    private function autoMatchRate(Collection $orders): ?int
    {
        $confirmed = 0;
        $corrected = 0;

        foreach ($orders as $order) {
            foreach (data_get($order->validation, 'items', []) ?: [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $status = $item['validation_status'] ?? null;
                if ($status === 'corrected') {
                    $corrected++;
                } elseif (in_array($status, ['confirmed', 'pending'], true) && data_get($item, 'match')) {
                    $confirmed++;
                }
            }
            $corrected += count(data_get($order->validation, 'corrections', []) ?: []);
        }

        $total = $confirmed + $corrected;
        if ($total === 0) {
            return null;
        }

        return (int) round(($confirmed / $total) * 100);
    }

    private function pct(int $from, int $to): ?float
    {
        if ($from <= 0) {
            return null;
        }

        return round(($to / $from) * 100, 1);
    }
}

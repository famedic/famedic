<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClinicalOrder;
use App\Models\Customer;
use App\Services\ClinicalLearning\AiLearningService;
use App\Services\ClinicalLearning\LearningSuggestionRecorderInterface;
use App\Services\ClinicalMatching\ClinicalMatchingEngine;
use App\Services\ClinicalOrder\AiOperationsCenterService;
use App\Services\ClinicalOrder\ClinicalOrderService;
use App\Services\ClinicalOrder\FulfillClinicalOrderCartAction;
use App\Services\CommercialIntegration\CommercialActionService;
use App\Services\DocumentInterpretation\ClinicalInterpretationOrchestrator;
use App\Services\DocumentInterpretation\Exceptions\InterpretationFailedException;
use App\Services\DocumentInterpretation\Exceptions\InvalidInterpretationJsonException;
use App\Services\DocumentInterpretation\Prompts\PromptProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class ClinicalInterpreterController extends Controller
{
    private function authorizeClinicalInterpreter(Request $request): void
    {
        try {
            $allowed = $request->user()->administrator->hasPermissionTo('clinical-interpreter.manage');
        } catch (PermissionDoesNotExist) {
            $allowed = false;
        }

        $allowed || abort(403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeClinicalInterpreter($request);

        $recentOrders = $this->recentClinicalOrders(8);

        return Inertia::render('Admin/ClinicalInterpreter/Index', [
            'recent_orders' => $recentOrders,
            'recent_interpretations' => $recentOrders,
            'quick_links' => [
                [
                    'id' => 'new',
                    'label' => 'Nueva interpretación',
                    'href' => route('admin.clinical-interpreter.assistant'),
                    'description' => 'Asistente guiado: Interpretar → Validar → Finalizar',
                ],
                [
                    'id' => 'orders',
                    'label' => 'Clinical Orders',
                    'href' => route('admin.clinical-interpreter.orders.index'),
                    'description' => 'Listado de órdenes clínicas de laboratorio confirmadas',
                ],
                [
                    'id' => 'learning',
                    'label' => 'AI Learning',
                    'href' => route('admin.clinical-interpreter.learning'),
                    'description' => 'Correcciones frecuentes de estudios de laboratorio',
                ],
                [
                    'id' => 'operations',
                    'label' => 'AI Operations Center',
                    'href' => route('admin.clinical-interpreter.operations'),
                    'description' => 'Consola enterprise: KPIs, prompts, confianza y salud del sistema',
                ],
                [
                    'id' => 'config',
                    'label' => 'Configuración IA',
                    'href' => route('admin.clinical-interpreter.config'),
                    'description' => 'Prompts, modelo y versión activa',
                ],
            ],
            'meta' => [
                'product' => 'AI Laboratory Interpreter',
                'tagline' => 'Interpreta órdenes de laboratorio médico y conéctalas al catálogo Famedic con validación humana.',
            ],
        ]);
    }

    public function assistant(Request $request): Response
    {
        $this->authorizeClinicalInterpreter($request);

        return Inertia::render('Admin/ClinicalInterpreter/Assistant', [
            'meta' => [
                'phase' => 1,
                'note' => 'FASE 1 · Shell visual. Sin lógica de negocio.',
            ],
        ]);
    }

    public function history(Request $request): Response
    {
        $this->authorizeClinicalInterpreter($request);

        return Inertia::render('Admin/ClinicalInterpreter/History', [
            'orders' => $this->recentClinicalOrders(50),
            'meta' => [
                'title' => 'Historial',
                'description' => 'Interpretaciones y Clinical Orders recientes.',
            ],
        ]);
    }

    public function ordersIndex(Request $request): Response
    {
        $this->authorizeClinicalInterpreter($request);

        if (! Schema::hasTable('clinical_orders')) {
            return Inertia::render('Admin/ClinicalInterpreter/Orders/Index', [
                'orders' => [
                    'data' => [],
                    'links' => [],
                    'prev_page_url' => null,
                    'next_page_url' => null,
                ],
            ]);
        }

        $orders = ClinicalOrder::query()
            ->with('user')
            ->latest()
            ->paginate(25)
            ->through(fn (ClinicalOrder $order) => [
                ...$order->toSummaryArray(),
                'patient_name' => $order->patient['name'] ?? null,
                'show_url' => route('admin.clinical-interpreter.clinical-orders.show', $order->uuid),
            ]);

        return Inertia::render('Admin/ClinicalInterpreter/Orders/Index', [
            'orders' => $orders,
        ]);
    }

    public function learning(Request $request, AiLearningService $learning): Response
    {
        $this->authorizeClinicalInterpreter($request);

        $payload = $learning->dashboardPayload(80);
        $corrections = collect($payload['frequent_corrections'] ?? []);

        $asArray = fn ($row) => is_array($row) ? $row : (array) $row;

        return Inertia::render('Admin/ClinicalInterpreter/Learning', [
            ...$payload,
            'frequent_corrections' => $corrections
                ->map($asArray)
                ->filter(fn ($row) => ($row['type'] ?? null) === 'laboratory')
                ->values()
                ->all(),
            'abbreviations' => $corrections
                ->map($asArray)
                ->filter(fn ($row) => ($row['type'] ?? null) === 'laboratory'
                    && mb_strlen((string) ($row['detected_text'] ?? '')) <= 10)
                ->take(20)
                ->values()
                ->all(),
            'top_laboratory' => $corrections
                ->map($asArray)
                ->filter(fn ($row) => ($row['type'] ?? null) === 'laboratory')
                ->take(20)
                ->values()
                ->all(),
            'top_medication' => [],
            'meta' => [
                ...(is_array($payload['meta'] ?? null) ? $payload['meta'] : []),
                'note' => 'v1.0 · Solo correcciones de estudios de laboratorio. Farmacia permanece en arquitectura.',
            ],
        ]);
    }

    public function config(Request $request, PromptProvider $prompts): Response
    {
        $this->authorizeClinicalInterpreter($request);

        return Inertia::render('Admin/ClinicalInterpreter/Config', [
            'active' => $prompts->active()->toConfigArray(),
            'catalog' => $prompts->catalogForUi(),
            'meta' => [
                'note' => 'Prompts de solo lectura vía PromptProvider.',
            ],
        ]);
    }

    public function operations(Request $request, AiOperationsCenterService $operations): Response
    {
        $this->authorizeClinicalInterpreter($request);

        $filters = $request->only([
            'q',
            'status',
            'operator',
            'laboratory',
            'model',
            'prompt',
            'confidence',
            'from',
            'to',
        ]);

        $payload = $operations->build($filters);

        return Inertia::render('Admin/ClinicalInterpreter/OperationsCenter', [
            ...$payload,
            'module' => $request->string('module')->toString() ?: 'overview',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentClinicalOrders(int $limit): array
    {
        if (! Schema::hasTable('clinical_orders')) {
            return [];
        }

        return ClinicalOrder::query()
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ClinicalOrder $order) => [
                ...$order->toSummaryArray(),
                'patient_name' => $order->patient['name'] ?? null,
                'show_url' => route('admin.clinical-interpreter.clinical-orders.show', $order->uuid),
            ])
            ->all();
    }

    public function matching(Request $request, ClinicalInterpretationOrchestrator $orchestrator): Response
    {
        $this->authorizeClinicalInterpreter($request);

        return Inertia::render(
            'Admin/ClinicalInterpreter/MatchingEngine',
            $orchestrator->matchingShell()
        );
    }

    public function interpret(Request $request, ClinicalInterpretationOrchestrator $orchestrator)
    {
        $this->authorizeClinicalInterpreter($request);

        $validated = $request->validate([
            'document' => [
                'required',
                'file',
                'image',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,gif',
            ],
        ]);

        try {
            $payload = $orchestrator->interpretAndMatch($validated['document']);
        } catch (InvalidInterpretationJsonException $e) {
            return response()->json([
                'ok' => false,
                'error_type' => 'invalid_json',
                'message' => $e->getMessage(),
            ], 422);
        } catch (InterpretationFailedException $e) {
            return response()->json([
                'ok' => false,
                'error_type' => 'interpretation_failed',
                'message' => $e->getMessage() ?: 'No fue posible interpretar la receta.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error_type' => 'unexpected',
                'message' => 'No fue posible interpretar la receta. Intenta de nuevo.',
            ], 500);
        }

        Log::info('clinical_interpreter.interpret', [
            'user_id' => $request->user()->id,
            'session_id' => $payload['interpretation']['session_id'] ?? null,
            'prompt_key' => $payload['interpretation_metrics']['prompt_key'] ?? null,
            'model' => $payload['interpretation_metrics']['model'] ?? null,
            'filename' => $payload['document']['filename'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            ...$payload,
        ]);
    }

    public function searchCatalog(Request $request, ClinicalMatchingEngine $engine)
    {
        $this->authorizeClinicalInterpreter($request);

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'type' => ['nullable', 'string', 'in:all,medication,laboratory'],
        ]);

        return response()->json([
            'results' => $engine->searchCatalog(
                $validated['q'],
                $validated['type'] ?? 'all'
            ),
        ]);
    }

    public function recordLearning(
        Request $request,
        LearningSuggestionRecorderInterface $recorder,
    ) {
        $this->authorizeClinicalInterpreter($request);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:medication,laboratory'],
            'detected_text' => ['required', 'string', 'max:500'],
            'confirmed_text' => ['required', 'string', 'max:500'],
            'confirmed_catalog_id' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'in:corrected,confirmed,ignored'],
            'session_id' => ['nullable', 'string', 'max:100'],
            'meta' => ['nullable', 'array'],
        ]);

        $recorder->record((int) $request->user()->id, $validated);

        return response()->json(['ok' => true]);
    }

    public function commercialProposal(Request $request, CommercialActionService $commerce)
    {
        $this->authorizeClinicalInterpreter($request);
        $context = $this->validateClinicalOrderContext($request);
        $result = $commerce->proposal(
            $context['validation_items'],
            $context['session_id'] ?? null,
            $request->user(),
            $context
        );

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }

    public function commercialDraft(Request $request, CommercialActionService $commerce)
    {
        $this->authorizeClinicalInterpreter($request);
        $context = $this->validateClinicalOrderContext($request);

        try {
            return response()->json(
                $commerce->saveDraft($request->user(), $context)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function commercialQuote(Request $request, CommercialActionService $commerce)
    {
        $this->authorizeClinicalInterpreter($request);
        $context = $this->validateClinicalOrderContext($request);

        try {
            return response()->json(
                $commerce->prepareQuote($request->user(), $context)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function commercialCart(Request $request, CommercialActionService $commerce)
    {
        $this->authorizeClinicalInterpreter($request);
        $context = $this->validateClinicalOrderContext($request);

        try {
            return response()->json(
                $commerce->prepareCart($request->user(), $context)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function storeClinicalOrder(Request $request, ClinicalOrderService $orders)
    {
        $this->authorizeClinicalInterpreter($request);
        $context = $this->validateClinicalOrderContext($request, requireConfirmedItems: true);

        try {
            $order = $orders->save($request->user(), array_merge($context, [
                'status' => 'validated',
            ]));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        Log::info('clinical_interpreter.order_saved', [
            'user_id' => $request->user()->id,
            'order_uuid' => $order->uuid,
            'status' => $order->status?->value ?? $order->status,
            'session_id' => $order->session_id,
        ]);

        return response()->json([
            'ok' => true,
            'clinical_order' => $order->toSummaryArray(),
            'clinical_order_detail' => $order->toDetailArray(),
            'message' => 'Clinical Order guardada.',
        ]);
    }

    public function showClinicalOrder(
        Request $request,
        string $clinicalOrder,
        ClinicalOrderService $orders,
    ): Response {
        $this->authorizeClinicalInterpreter($request);
        $order = $orders->findByUuid($clinicalOrder);
        abort_unless($order, 404);

        return Inertia::render('Admin/ClinicalInterpreter/ClinicalOrderShow', [
            'order' => $order->toDetailArray(),
        ]);
    }

    public function clinicalOrderQuote(
        Request $request,
        string $clinicalOrder,
        ClinicalOrderService $orders,
    ) {
        $this->authorizeClinicalInterpreter($request);
        $order = $orders->findByUuid($clinicalOrder);
        abort_unless($order, 404);

        try {
            $order = $orders->markQuotePrepared($order);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::info('clinical_interpreter.order_quote', [
            'user_id' => $request->user()->id,
            'order_uuid' => $order->uuid,
            'status' => $order->status?->value,
        ]);

        return response()->json([
            'ok' => true,
            'clinical_order' => $order->toSummaryArray(),
            'quote_payload' => $order->quote_payload,
            'message' => 'Cotización preparada desde Clinical Order.',
        ]);
    }

    public function clinicalOrderCart(
        Request $request,
        string $clinicalOrder,
        ClinicalOrderService $orders,
        FulfillClinicalOrderCartAction $fulfillCart,
    ) {
        $this->authorizeClinicalInterpreter($request);
        $order = $orders->findByUuid($clinicalOrder);
        abort_unless($order, 404);

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ]);

        $customer = Customer::query()
            ->with(['user', 'contacts'])
            ->findOrFail((int) $validated['customer_id']);

        try {
            $result = $fulfillCart(
                $order,
                $customer,
                isset($validated['contact_id']) ? (int) $validated['contact_id'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $fresh = $result['clinical_order'];

        Log::info('clinical_interpreter.order_cart_fulfilled', [
            'user_id' => $request->user()->id,
            'order_uuid' => $fresh->uuid,
            'customer_id' => $result['customer_id'],
            'brand' => $result['brand'],
            'status' => $fresh->status?->value ?? $fresh->status,
        ]);

        return response()->json([
            'ok' => true,
            'clinical_order' => $fresh->toSummaryArray(),
            'clinical_order_detail' => $fresh->toDetailArray(),
            'checkout_url' => $result['checkout_url'],
            'brand' => $result['brand'],
            'brand_label' => $result['brand_label'],
            'customer_id' => $result['customer_id'],
            'contact_id' => $result['contact_id'],
            'laboratory_test_ids' => $result['laboratory_test_ids'],
            'cart_item_ids' => $result['cart_item_ids'],
            'message' => 'Carrito preparado. Continúa en el Checkout Famedic para paciente, dirección y pago.',
        ]);
    }

    public function searchCustomers(Request $request)
    {
        $this->authorizeClinicalInterpreter($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));

        $query = Customer::query()
            ->with(['user', 'contacts'])
            ->withCount('contacts');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->whereHas('user', function ($userQuery) use ($q) {
                    $userQuery
                        ->where('email', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%");
                })->orWhereHas('contacts', function ($contactQuery) use ($q) {
                    $contactQuery
                        ->where('name', 'like', "%{$q}%")
                        ->orWhere('paternal_lastname', 'like', "%{$q}%")
                        ->orWhere('maternal_lastname', 'like', "%{$q}%");
                });
            });
        }

        $customers = $query
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(function (Customer $customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->user?->full_name
                        ?? $customer->user?->name
                        ?? "Cliente #{$customer->id}",
                    'email' => $customer->user?->email,
                    'phone' => $customer->user?->phone,
                    'has_user' => (bool) $customer->user,
                    'contacts' => $customer->contacts->map(fn ($contact) => [
                        'id' => $contact->id,
                        'name' => trim(collect([
                            $contact->name,
                            $contact->paternal_lastname,
                            $contact->maternal_lastname,
                        ])->filter()->implode(' ')),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'customers' => $customers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateClinicalOrderContext(Request $request, bool $requireConfirmedItems = false): array
    {
        $validated = $request->validate([
            'session_id' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'max:80'],
            'items.*.detection_id' => ['nullable', 'string', 'max:100'],
            'items.*.type' => ['required', 'string', 'in:laboratory,medication'],
            'items.*.validation_status' => [
                'required',
                'string',
                Rule::in(['pending', 'confirmed', 'corrected', 'ignored']),
            ],
            'items.*.detected_name' => ['nullable', 'string', 'max:500'],
            'items.*.selected_catalog_id' => ['nullable', 'string', 'max:100'],
            'items.*.match' => ['nullable', 'array'],
            'items.*.match.catalog_id' => ['nullable', 'string', 'max:100'],
            'items.*.match.name' => ['nullable', 'string', 'max:500'],
            'items.*.match.code' => ['nullable', 'string', 'max:100'],
            'items.*.match.sku' => ['nullable', 'string', 'max:100'],
            'items.*.match.laboratory' => ['nullable', 'string', 'max:200'],
            'items.*.match.brand' => ['nullable', 'string', 'max:200'],
            'items.*.match.available' => ['nullable', 'boolean'],
            'items.*.match.delivery_time' => ['nullable', 'string', 'max:200'],
            'document' => ['nullable', 'array'],
            'document.filename' => ['nullable', 'string', 'max:255'],
            'document.mime' => ['nullable', 'string', 'max:100'],
            'document.pages' => ['nullable', 'integer', 'min:1', 'max:50'],
            'document.uploaded_at' => ['nullable', 'string', 'max:64'],
            'interpretation' => ['nullable', 'array'],
            'metrics' => ['nullable', 'array'],
        ]);

        $items = array_map(function (array $item) {
            if (isset($item['match']) && is_array($item['match'])) {
                // Never trust client-side prices — Commercial engine loads from DB.
                unset(
                    $item['match']['price_cents'],
                    $item['match']['price'],
                    $item['match']['famedic_price_cents'],
                );
            }

            return $item;
        }, $validated['items']);

        if ($requireConfirmedItems) {
            $actionable = collect($items)->filter(
                fn ($i) => in_array($i['validation_status'] ?? null, ['confirmed', 'corrected'], true)
            );
            if ($actionable->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Se requiere al menos un ítem confirmado o corregido.',
                ]);
            }
        }

        $document = $validated['document'] ?? null;
        if (is_array($document)) {
            // Never accept/persist data-URL previews (PHI).
            unset($document['preview_url'], $document['contents'], $document['base64']);
        }

        return [
            'session_id' => $validated['session_id'] ?? null,
            'validation_items' => $items,
            'document' => $document,
            'interpretation' => $validated['interpretation'] ?? null,
            'metrics' => $validated['metrics'] ?? null,
        ];
    }
}

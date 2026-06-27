<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Services\Laboratory\GdaNotificationSimulatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GdaNotificationSimulatorController extends Controller
{
    public function __construct(
        protected GdaNotificationSimulatorService $simulatorService
    ) {}

    public function show(Request $request): Response
    {
        $this->ensureSimulatorAccess($request);

        $purchases = LaboratoryPurchase::query()
            ->with(['customer.user:id,name,paternal_lastname,maternal_lastname,email'])
            ->withCount('laboratoryPurchaseItems')
            ->latest('id')
            ->limit(40)
            ->get(['id', 'customer_id', 'gda_order_id', 'gda_consecutivo', 'brand', 'created_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $user = $purchase->customer?->user;
                $customerLabel = $user
                    ? trim("{$user->name} {$user->paternal_lastname} {$user->maternal_lastname}")
                    : ('Cliente #'.$purchase->customer_id);

                return [
                    'id' => $purchase->id,
                    'gda_order_id' => $purchase->gda_order_id,
                    'gda_consecutivo' => $purchase->gda_consecutivo,
                    'brand' => $purchase->brand?->value,
                    'brand_label' => $purchase->brand?->label() ?? '—',
                    'studies_count' => (int) $purchase->laboratory_purchase_items_count,
                    'created_at' => $purchase->formatted_created_at ?? $purchase->created_at?->format('d/m/Y H:i'),
                    'customer_label' => $customerLabel !== '' ? $customerLabel : ($user?->email ?? 'Cliente #'.$purchase->customer_id),
                    'has_gda_reference' => filled($purchase->gda_order_id) || filled($purchase->gda_consecutivo),
                ];
            });

        return Inertia::render('Admin/Simulators/GdaNotifications', [
            'purchases' => $purchases,
            'webhookUrl' => url('/api/laboratory/webhook/notifications'),
        ]);
    }

    public function history(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $this->ensureSimulatorAccess($request);

        return response()->json(
            $this->simulatorService->historyForPurchase($laboratoryPurchase)
        );
    }

    public function simulate(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $this->ensureSimulatorAccess($request);

        $validated = $request->validate([
            'notification_type' => ['required', Rule::in(['sample_collection', 'results'])],
            'send_email' => ['required', 'boolean'],
            'laboratory_purchase_item_id' => ['nullable', 'integer'],
        ]);

        $result = $this->simulatorService->simulate(
            $laboratoryPurchase,
            $validated['notification_type'],
            (bool) $validated['send_email'],
            isset($validated['laboratory_purchase_item_id'])
                ? (int) $validated['laboratory_purchase_item_id']
                : null
        );

        $history = $this->simulatorService->historyForPurchase($laboratoryPurchase);

        return response()->json(array_merge($result, [
            'history' => $history,
        ]));
    }

    public function resend(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $this->ensureSimulatorAccess($request);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['sample_collection', 'results'])],
        ]);

        $result = $this->simulatorService->resendEmail(
            $laboratoryPurchase,
            $validated['type']
        );

        $history = $this->simulatorService->historyForPurchase($laboratoryPurchase);

        return response()->json(array_merge($result, [
            'history' => $history,
        ]));
    }

    private function ensureSimulatorAccess(Request $request): void
    {
        $request->user()->administrator->hasPermissionTo('simulators.manage') || abort(403);
    }
}

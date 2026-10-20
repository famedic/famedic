<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryGdaFailureOperation;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryGdaFailureLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryGdaFailureLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($this->canView($request), 403);

        $filters = collect($request->only(['search', 'operation', 'brand']))->filter()->all();

        $logs = LaboratoryGdaFailureLog::query()
            ->adminIndex($filters)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LaboratoryGdaFailureLog $log) => [
                'id' => $log->id,
                'operation' => $log->operation->value,
                'operation_label' => $log->operation->label(),
                'laboratory_purchase_id' => $log->laboratory_purchase_id,
                'source_laboratory_purchase_id' => $log->source_laboratory_purchase_id,
                'brand' => $log->brand?->value,
                'failure_reason' => $log->failure_reason,
                'gda_code_http' => $log->gda_code_http,
                'gda_mensaje' => $log->gda_mensaje,
                'gda_description' => $log->gda_description,
                'message' => $log->message,
                'requisition_value' => $log->requisition_value,
                'customer_name' => $log->customer?->user?->full_name,
                'customer_email' => $log->customer?->user?->email,
                'administrator_name' => $log->administratorUser?->full_name,
                'created_at' => $log->created_at?->isoFormat('D MMM YYYY h:mm a'),
            ]);

        return Inertia::render('Admin/LaboratoryGdaFailureLogs', [
            'logs' => $logs,
            'filters' => $filters,
            'operations' => collect(LaboratoryGdaFailureOperation::cases())
                ->map(fn (LaboratoryGdaFailureOperation $operation) => [
                    'value' => $operation->value,
                    'label' => $operation->label(),
                ])
                ->values()
                ->all(),
            'brands' => LaboratoryBrand::brandsData(),
        ]);
    }

    public function show(Request $request, LaboratoryGdaFailureLog $laboratoryGdaFailureLog)
    {
        abort_unless($this->canView($request), 403);

        $laboratoryGdaFailureLog->load([
            'laboratoryPurchase.customer.user',
            'laboratoryPurchase.laboratoryPurchaseItems',
            'sourceLaboratoryPurchase',
            'administratorUser',
        ]);

        return Inertia::render('Admin/LaboratoryGdaFailureLog', [
            'log' => [
                'id' => $laboratoryGdaFailureLog->id,
                'operation' => $laboratoryGdaFailureLog->operation->value,
                'operation_label' => $laboratoryGdaFailureLog->operation->label(),
                'laboratory_purchase_id' => $laboratoryGdaFailureLog->laboratory_purchase_id,
                'source_laboratory_purchase_id' => $laboratoryGdaFailureLog->source_laboratory_purchase_id,
                'brand' => $laboratoryGdaFailureLog->brand?->value,
                'failure_reason' => $laboratoryGdaFailureLog->failure_reason,
                'gda_code_http' => $laboratoryGdaFailureLog->gda_code_http,
                'gda_mensaje' => $laboratoryGdaFailureLog->gda_mensaje,
                'gda_description' => $laboratoryGdaFailureLog->gda_description,
                'http_status' => $laboratoryGdaFailureLog->http_status,
                'requisition_value' => $laboratoryGdaFailureLog->requisition_value,
                'message' => $laboratoryGdaFailureLog->message,
                'response_summary' => $laboratoryGdaFailureLog->response_summary,
                'context' => $laboratoryGdaFailureLog->context,
                'customer' => $laboratoryGdaFailureLog->customer?->user ? [
                    'full_name' => $laboratoryGdaFailureLog->customer->user->full_name,
                    'email' => $laboratoryGdaFailureLog->customer->user->email,
                ] : null,
                'administrator_name' => $laboratoryGdaFailureLog->administratorUser?->full_name,
                'purchase_items' => $laboratoryGdaFailureLog->laboratoryPurchase?->laboratoryPurchaseItems
                    ?->map(fn ($item) => [
                        'name' => $item->name,
                        'gda_id' => $item->gda_id,
                        'formatted_price' => $item->formatted_price ?? null,
                    ])
                    ->values()
                    ->all(),
                'created_at' => $laboratoryGdaFailureLog->created_at?->isoFormat('D MMM YYYY h:mm a'),
            ],
        ]);
    }

    private function canView(Request $request): bool
    {
        return (bool) $request->user()?->administrator?->hasPermissionTo('laboratory-purchases.manage');
    }
}

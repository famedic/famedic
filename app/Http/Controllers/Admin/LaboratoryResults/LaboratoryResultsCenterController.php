<?php

namespace App\Http\Controllers\Admin\LaboratoryResults;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryResults\Admin\LaboratoryResultsCenterPresenter;
use App\Services\LaboratoryResults\Admin\LaboratoryResultsCenterQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LaboratoryResultsCenterController extends Controller
{
    public function index(
        Request $request,
        LaboratoryResultsCenterQuery $query,
        LaboratoryResultsCenterPresenter $presenter,
    ): Response {
        $this->authorizeAccess($request);

        $filters = $request->only([
            'purchase_id',
            'folio',
            'status',
            'extraction_status',
            'structured_status',
            'extraction_method',
            'has_errors',
            'ai_explanation_status',
            'date_from',
            'date_to',
        ]);

        $purchases = $query->paginate($filters);

        return Inertia::render('Admin/LaboratoryResults/Center', [
            'filters' => $filters,
            'filterOptions' => $presenter->filterOptions(),
            'summary' => $presenter->summary($purchases->getCollection()),
            'purchases' => $purchases->through(fn (LaboratoryPurchase $purchase) => $presenter->row($purchase)),
        ]);
    }

    public function show(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        LaboratoryResultsCenterQuery $query,
        LaboratoryResultsCenterPresenter $presenter,
    ): Response {
        $this->authorizeAccess($request);

        $purchase = $query->detail($laboratoryPurchase);

        return Inertia::render('Admin/LaboratoryResults/Center', [
            'filters' => $request->only([
                'purchase_id',
                'folio',
                'status',
                'extraction_status',
                'structured_status',
                'extraction_method',
                'has_errors',
                'ai_explanation_status',
                'date_from',
                'date_to',
            ]),
            'filterOptions' => $presenter->filterOptions(),
            'summary' => [],
            'purchases' => null,
            'detail' => $presenter->detail($purchase),
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(
            $request->user()?->administrator?->hasPermissionTo('laboratory-notifications.monitor') ?? false,
            403,
        );
    }
}

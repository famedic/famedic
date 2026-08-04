<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingInvoicesRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingInvoicesQuery;
use Inertia\Inertia;
use Inertia\Response;

class InvoicesController extends Controller
{
    public function __invoke(
        IndexLaboratoryBillingInvoicesRequest $request,
        LaboratoryBillingInvoicesQuery $query,
    ): Response {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only([
            'search',
            'document',
            'from',
            'to',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $filters = array_merge($filters, $range->toFilterArray());

        return Inertia::render('Admin/LaboratoryBilling/Invoices', [
            'invoices' => $query->paginate($filters, $range),
            'filters' => $filters,
        ]);
    }
}

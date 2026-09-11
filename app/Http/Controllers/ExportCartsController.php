<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Carts\ExportCartsRequest;
use App\Exports\CartsExport;
use App\Jobs\ProcessCartsSpreadsheetExport;

class ExportCartsController extends Controller
{
    public function __invoke(ExportCartsRequest $request)
    {
        $filters = collect($request->only([
            'search',
            'type',
            'display_status',
            'operational_filter',
            'operational_bucket',
            'payment_status',
            'checkout_stage',
            'appointment_filter',
            'contact_filter',
            'customer_segment',
            'brand',
            'amount_range',
            'inactivity_range',
            'start_date',
            'end_date',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        dispatch(new ProcessCartsSpreadsheetExport($request->user(), CartsExport::normalizeFilters($filters)));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

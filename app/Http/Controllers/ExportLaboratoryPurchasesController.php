<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\LaboratoryPurchases\ExportLaboratoryPurchasesRequest;
use App\Jobs\ProcessLaboratoryPurchasesSpreadsheetExport;

class ExportLaboratoryPurchasesController extends Controller
{
    public function __invoke(ExportLaboratoryPurchasesRequest $request)
    {
        $filters = collect($request->only([
            'search',
            'deleted',
            'start_date',
            'end_date',
            'invoice_requested',
            'invoice_uploaded',
            'results_uploaded',
            'payment_method',
        ]))->filter()->all();

        dispatch(new ProcessLaboratoryPurchasesSpreadsheetExport($request->user(), $filters));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

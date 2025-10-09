<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Customers\ExportCustomersRequest;
use App\Jobs\ProcessCustomersSpreadsheetExport;

class ExportCustomersController extends Controller
{
    public function __invoke(ExportCustomersRequest $request)
    {
        $filters = collect($request->only(
            'search',
            'type',
            'medical_attention_status',
            'referral_status',
            'start_date',
            'end_date'
        ))->filter()->all();

        dispatch(new ProcessCustomersSpreadsheetExport($request->user(), $filters));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

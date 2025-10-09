<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\MedicalAttentionSubscriptions\ExportMedicalAttentionSubscriptionsRequest;
use App\Jobs\ProcessMedicalAttentionSubscriptionsSpreadsheetExport;

class ExportMedicalAttentionSubscriptionsController extends Controller
{
    public function __invoke(ExportMedicalAttentionSubscriptionsRequest $request)
    {
        $filters = collect($request->only('search', 'status', 'start_date', 'end_date', 'payment_method'))->filter()->all();

        dispatch(new ProcessMedicalAttentionSubscriptionsSpreadsheetExport($request->user(), $filters));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

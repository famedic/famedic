<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\OnlinePharmacyPurchases\ExportOnlinePharmacyPurchasesRequest;
use App\Jobs\ProcessOnlinePharmacyPurchasesSpreadsheetExport;

class ExportOnlinePharmacyPurchasesController extends Controller
{
    public function __invoke(ExportOnlinePharmacyPurchasesRequest $request)
    {
        $filters = collect($request->only('search', 'deleted', 'start_date', 'end_date', 'payment_method'))->filter()->all();

        dispatch(new ProcessOnlinePharmacyPurchasesSpreadsheetExport($request->user(), $filters));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Carts\ExportCartsRequest;
use App\Jobs\ProcessCartsSpreadsheetExport;

class ExportCartsController extends Controller
{
    public function __invoke(ExportCartsRequest $request)
    {
        $filters = collect($request->only([
            'search',
            'type',
            'display_status',
            'start_date',
            'end_date',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        dispatch(new ProcessCartsSpreadsheetExport($request->user(), $filters));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

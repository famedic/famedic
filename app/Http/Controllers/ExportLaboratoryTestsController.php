<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\LaboratoryTests\ExportLaboratoryTestsRequest;
use App\Jobs\ProcessLaboratoryTestsSpreadsheetExport;

class ExportLaboratoryTestsController extends Controller
{
    public function __invoke(ExportLaboratoryTestsRequest $request)
    {
        $filters = collect($request->only([
            'search',
            'brand',
            'category',
            'requires_appointment',
        ]))->filter()->all();

        ProcessLaboratoryTestsSpreadsheetExport::dispatch(
            $request->user(),
            $filters
        );

        return redirect()->back()->flashMessage('Tu reporte se está generando y será enviado a tu correo electrónico en unos minutos.');
    }
}

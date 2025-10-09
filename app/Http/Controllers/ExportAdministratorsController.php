<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Administrators\ExportAdministratorsRequest;
use App\Jobs\ProcessAdministratorsSpreadsheetExport;

class ExportAdministratorsController extends Controller
{
    public function __invoke(ExportAdministratorsRequest $request)
    {
        $filters = collect($request->only('search', 'laboratory_concierge', 'role'))->filter()->all();

        dispatch(new ProcessAdministratorsSpreadsheetExport($request->user(), $filters));

        return back()->flashMessage('Tu reporte se está generando, te llegará por correo en unos minutos.');
    }
}

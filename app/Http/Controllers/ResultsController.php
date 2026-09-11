<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratories\ShowLaboratoryResultsRequest;
use App\Actions\Laboratories\EnsureLatestGdaResultsPdfAction;
use App\Actions\Laboratories\RecordPatientResultsAccessAction;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ResultsController extends Controller
{
    public function __invoke(ShowLaboratoryResultsRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        app(EnsureLatestGdaResultsPdfAction::class)->execute(
            $laboratoryPurchase,
            'patient_results'
        );

        $laboratoryPurchase->refresh();

        if (empty($laboratoryPurchase->results) || ! Storage::exists($laboratoryPurchase->results)) {
            abort(404, 'Resultado no disponible');
        }

        $url = Storage::temporaryUrl(
            $laboratoryPurchase->results,
            now()->addMinutes(5)
        );

        app(RecordPatientResultsAccessAction::class)->execute($laboratoryPurchase);

        return Inertia::location($url);
    }
}

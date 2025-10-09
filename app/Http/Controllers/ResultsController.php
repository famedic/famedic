<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratories\ShowLaboratoryResultsRequest;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ResultsController extends Controller
{
    public function __invoke(ShowLaboratoryResultsRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        return Inertia::location(
            Storage::temporaryUrl(
                $laboratoryPurchase->results,
                now()->addMinutes(5)
            )
        );
    }
}

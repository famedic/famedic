<?php

namespace App\Http\Controllers;

use App\Http\Resources\LaboratoryStructuredPatientResultResource;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryResults\Patient\LaboratoryPatientStructuredResultQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaboratoryStructuredPatientResultController extends Controller
{
    public function __construct(
        private readonly LaboratoryPatientStructuredResultQuery $structuredResultQuery,
    ) {}

    public function show(Request $request, LaboratoryPurchase $laboratoryPurchase): JsonResponse
    {
        $this->authorize('view', $laboratoryPurchase);

        $report = $this->structuredResultQuery->activePublishedReportForPurchase($laboratoryPurchase);

        if ($report === null) {
            return response()->json([
                'message' => 'Los resultados estructurados aún no están disponibles.',
            ], 404);
        }

        return response()->json([
            'data' => new LaboratoryStructuredPatientResultResource($report),
        ]);
    }
}

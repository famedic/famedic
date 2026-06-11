<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsFeatureDisabled;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LaboratoryTestResource;
use App\Http\Responses\ApiResponse;
use App\Models\LaboratoryTest;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    use RespondsFeatureDisabled;

    public function showLaboratoryTest(int $laboratoryTestId): JsonResponse
    {
        $laboratoryTest = LaboratoryTest::query()
            ->with('laboratoryTestCategory')
            ->find($laboratoryTestId);

        if (! $laboratoryTest) {
            return ApiResponse::error(
                'LAB_TEST_NOT_FOUND',
                'Estudio de laboratorio no encontrado.',
                404,
            );
        }

        return ApiResponse::success(
            (new LaboratoryTestResource($laboratoryTest))->resolve(),
        );
    }

    public function showMedication(int $medicationId): JsonResponse
    {
        return $this->catalogUnavailable();
    }
}

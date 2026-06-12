<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\CreateAkubicaTaxProfileAction;
use App\Actions\Api\V1\UpdateAkubicaTaxProfileAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\StoreTaxProfileRequest;
use App\Http\Requests\Api\V1\User\UpdateTaxProfileRequest;
use App\Http\Resources\Api\V1\TaxProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\TaxProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserTaxProfileController extends Controller
{
    public function store(
        StoreTaxProfileRequest $request,
        CreateAkubicaTaxProfileAction $createTaxProfileAction,
    ): JsonResponse {
        $taxProfile = $createTaxProfileAction(
            $request->user()->customer,
            $request->validated('business_name'),
            $request->validated('rfc'),
            $request->validated('postal_code'),
            $request->validated('tax_regime'),
            $request->validated('cfdi_use'),
        );

        return ApiResponse::success([
            'tax_profile' => (new TaxProfileResource($taxProfile))->resolve($request),
        ], status: 201);
    }

    public function update(
        UpdateTaxProfileRequest $request,
        int $taxProfileId,
        UpdateAkubicaTaxProfileAction $updateTaxProfileAction,
    ): JsonResponse {
        $taxProfile = $this->findOwnedTaxProfile($request, $taxProfileId);

        if ($taxProfile instanceof JsonResponse) {
            return $taxProfile;
        }

        $result = $updateTaxProfileAction(
            $taxProfile,
            $request->validated('business_name'),
            $request->validated('rfc'),
            $request->validated('postal_code'),
            $request->validated('tax_regime'),
            $request->validated('cfdi_use'),
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'RFC_ALREADY_EXISTS' => ApiResponse::error(
                    'VALIDATION_ERROR',
                    'Ya existe otro perfil fiscal con este RFC.',
                    422,
                    ['rfc' => ['Ya existe otro perfil fiscal con este RFC.']],
                ),
                default => ApiResponse::error(
                    'INTERNAL_ERROR',
                    'Ocurrió un error inesperado.',
                    500,
                ),
            };
        }

        return ApiResponse::success([
            'tax_profile' => (new TaxProfileResource($result['tax_profile']))->resolve($request),
        ]);
    }

    public function destroy(
        Request $request,
        int $taxProfileId,
        DestroyTaxProfileAction $destroyTaxProfileAction,
    ): JsonResponse {
        $taxProfile = $this->findOwnedTaxProfile($request, $taxProfileId);

        if ($taxProfile instanceof JsonResponse) {
            return $taxProfile;
        }

        $destroyTaxProfileAction($taxProfile);

        return ApiResponse::success(['deleted' => true]);
    }

    private function findOwnedTaxProfile(Request $request, int $taxProfileId): TaxProfile|JsonResponse
    {
        $taxProfile = TaxProfile::query()
            ->where('id', $taxProfileId)
            ->where('customer_id', $request->user()->customer->id)
            ->first();

        if (! $taxProfile) {
            return ApiResponse::error(
                'TAX_PROFILE_NOT_FOUND',
                'El perfil fiscal no fue encontrado.',
                404,
            );
        }

        return $taxProfile;
    }
}

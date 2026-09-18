<?php

namespace App\Http\Controllers;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\LaboratoryCheckout\StoreSelectedLaboratoryStoreRequest;
use App\Models\LaboratoryStore;
use App\Services\Laboratory\SelectedLaboratoryStoreDraftService;
use App\Services\Laboratory\SelectedLaboratoryStoreException;
use Illuminate\Http\JsonResponse;

class LaboratoryCheckoutSelectedStoreController extends Controller
{
    public function show(
        LaboratoryBrand $laboratoryBrand,
        SelectedLaboratoryStoreDraftService $service,
    ): JsonResponse {
        $selection = $service->current(request()->user()->customer, $laboratoryBrand);

        return response()->json([
            'success' => true,
            'data' => $this->selectionPayload($selection),
        ]);
    }

    public function store(
        StoreSelectedLaboratoryStoreRequest $request,
        LaboratoryBrand $laboratoryBrand,
        SelectedLaboratoryStoreDraftService $service,
    ): JsonResponse {
        $store = LaboratoryStore::withTrashed()->findOrFail($request->integer('laboratory_store_id'));

        try {
            $selection = $service->store($request->user()->customer, $laboratoryBrand, $store);
        } catch (SelectedLaboratoryStoreException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => [
                    'reason' => $exception->reason,
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sucursal seleccionada correctamente.',
            'data' => [
                'selected' => true,
                'store' => $this->storePayload($selection['store']),
                'validation' => ['status' => 'valid'],
            ],
        ]);
    }

    public function destroy(
        LaboratoryBrand $laboratoryBrand,
        SelectedLaboratoryStoreDraftService $service,
    ): JsonResponse {
        $service->clear(request()->user()->customer, $laboratoryBrand);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal seleccionada eliminada correctamente.',
            'data' => [
                'selected' => false,
                'store' => null,
                'validation' => null,
            ],
        ]);
    }

    /**
     * @param  array{selected: bool, store: LaboratoryStore|null, validation: array<string, string>|null}  $selection
     * @return array<string, mixed>
     */
    private function selectionPayload(array $selection): array
    {
        return [
            'selected' => $selection['selected'],
            'store' => $selection['store'] ? $this->storePayload($selection['store']) : null,
            'validation' => $selection['validation'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(LaboratoryStore $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'brand' => $store->brand?->value ?? $store->brand,
            'address' => $store->address,
        ];
    }
}

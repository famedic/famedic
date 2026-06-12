<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Api\V1\Concerns\RespondsFeatureDisabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\ListLaboratoryStoresRequest;
use App\Http\Requests\Api\V1\Catalog\ListLaboratoryTestCategoriesRequest;
use App\Http\Requests\Api\V1\Catalog\ListLaboratoryTestsRequest;
use App\Http\Resources\Api\V1\LaboratoryBrandResource;
use App\Http\Resources\Api\V1\LaboratoryStoreResource;
use App\Http\Resources\Api\V1\LaboratoryTestCategoryResource;
use App\Http\Resources\Api\V1\LaboratoryTestListResource;
use App\Http\Resources\Api\V1\LaboratoryTestResource;
use App\Http\Responses\ApiResponse;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    use RespondsFeatureDisabled;

    public function indexLaboratoryBrands(Request $request): JsonResponse
    {
        $brands = collect(LaboratoryBrand::cases())
            ->map(fn (LaboratoryBrand $brand) => (new LaboratoryBrandResource($brand))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success(['brands' => $brands]);
    }

    public function indexLaboratoryTests(ListLaboratoryTestsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $filters = array_filter([
            'search' => $validated['search'] ?? null,
            'brand' => $validated['brand'] ?? null,
            'category' => $validated['category_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (array_key_exists('requires_appointment', $validated)) {
            $filters['requires_appointment'] = $validated['requires_appointment']
                ? 'required'
                : 'not_required';
        }

        $paginator = LaboratoryTest::query()
            ->with('laboratoryTestCategory')
            ->filter($filters)
            ->orderBy('name')
            ->paginate(
                perPage: $validated['per_page'] ?? 20,
                page: $validated['page'] ?? null,
            );

        return ApiResponse::success([
            'laboratory_tests' => LaboratoryTestListResource::collection($paginator->items())->resolve($request),
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function indexLaboratoryTestCategories(ListLaboratoryTestCategoriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $categories = LaboratoryTestCategory::query()
            ->when($validated['brand'] ?? null, function ($query, $brand) {
                $query->whereHas('laboratoryTests', fn ($tests) => $tests->where('brand', $brand));
            })
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'categories' => LaboratoryTestCategoryResource::collection($categories)->resolve($request),
        ]);
    }

    public function indexLaboratoryStores(ListLaboratoryStoresRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $filters = array_filter([
            'brand' => $validated['brand'] ?? null,
            'state' => $validated['state'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $stores = LaboratoryStore::query()
            ->filter($filters)
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'stores' => LaboratoryStoreResource::collection($stores)->resolve($request),
        ]);
    }

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

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}

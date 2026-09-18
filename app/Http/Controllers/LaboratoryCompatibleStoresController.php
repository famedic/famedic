<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratories\CompatibleLaboratoryStoresRequest;
use App\Http\Resources\CompatibleLaboratoryStoreResource;
use App\Models\LaboratoryCheckoutDraft;
use App\Services\Laboratory\PostalCodeLocationResolver;
use App\Services\LaboratoryRequirements\BranchResolver;
use App\Services\LaboratoryRequirements\CartRequirementAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class LaboratoryCompatibleStoresController extends Controller
{
    public function __invoke(
        CompatibleLaboratoryStoresRequest $request,
        CartRequirementAggregator $aggregator,
        BranchResolver $branchResolver,
        PostalCodeLocationResolver $postalCodeLocationResolver,
    ): JsonResponse {
        $brand = $request->brand();
        $customer = $request->user()->customer;
        $postalCode = $request->postalCode();
        $hasPostalCodeColumn = Schema::hasColumn('laboratory_checkout_drafts', 'postal_code');

        if ($request->shouldClearPostalCode() && $hasPostalCodeColumn) {
            LaboratoryCheckoutDraft::query()
                ->where('customer_id', $customer->id)
                ->where('laboratory_brand', $brand)
                ->update(['postal_code' => null]);

            $postalCode = null;
        } elseif ($postalCode !== null && $hasPostalCodeColumn) {
            LaboratoryCheckoutDraft::query()->updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'laboratory_brand' => $brand,
                ],
                ['postal_code' => $postalCode],
            );
        } elseif ($postalCode === null && $hasPostalCodeColumn) {
            $postalCode = LaboratoryCheckoutDraft::query()
                ->where('customer_id', $customer->id)
                ->where('laboratory_brand', $brand)
                ->value('postal_code');
        }

        $postalLocation = $postalCodeLocationResolver->resolve($postalCode, $brand);
        $location = $request->location() ?? $postalLocation['location'];

        $items = $request->user()
            ->customer
            ->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest.laboratoryTestCategory')
            ->get();

        if ($items->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'resolution_status' => 'empty_cart',
                    'is_resolvable' => false,
                    'unresolved_reasons' => ['empty_cart'],
                    'brands' => [],
                    'branches' => [],
                    'compatible_branches_count' => 0,
                    'meta' => [
                        'cart_items_count' => 0,
                        'brand' => $brand->value,
                        'postal_code' => $postalCode,
                        'postal_code_location_status' => $postalLocation['status'],
                        'postal_code_location_source' => $postalLocation['source'],
                        'postal_code_location_confidence' => $postalLocation['confidence'],
                        'postal_code_location_matches_count' => $postalLocation['matches_count'],
                        'location' => [
                            'postal_code' => $postalCode,
                            'status' => $postalLocation['status'],
                            'source' => $postalLocation['source'],
                            'confidence' => $postalLocation['confidence'],
                        ],
                        'appointment_availability' => 'unknown',
                        'availability_scope' => 'operational_capability_only',
                    ],
                ],
            ]);
        }

        $cartRequirements = $aggregator->aggregateItems($items, (string) $request->user()->customer->id);
        $resolution = $branchResolver->resolve($cartRequirements, $location, $request->requestedDate());
        $status = $cartRequirements->isResolvable ? 'resolved' : 'unknown';

        return response()->json([
            'success' => true,
            'data' => [
                'resolution_status' => $status,
                'is_resolvable' => $cartRequirements->isResolvable,
                'confidence' => $cartRequirements->confidence,
                'unresolved_reasons' => $cartRequirements->unresolvedReasons,
                'brands' => collect($resolution->brands)
                    ->map(fn (array $branches) => [
                        'branches' => collect($branches)
                            ->map(fn ($branch) => (new CompatibleLaboratoryStoreResource($branch, $cartRequirements))->resolve($request))
                            ->values()
                            ->all(),
                        'compatible_branches_count' => collect($branches)->where('isCompatible', true)->count(),
                    ])
                    ->all(),
                'branches' => collect($resolution->branches)
                    ->map(fn ($branch) => (new CompatibleLaboratoryStoreResource($branch, $cartRequirements))->resolve($request))
                    ->values()
                    ->all(),
                'compatible_branches_count' => collect($resolution->branches)->where('isCompatible', true)->count(),
                'reasons' => $resolution->reasons,
                'meta' => [
                    'cart_items_count' => $items->count(),
                    'brand' => $brand->value,
                    'postal_code' => $postalCode,
                    'postal_code_location_status' => $postalLocation['status'],
                    'postal_code_location_source' => $postalLocation['source'],
                    'postal_code_location_confidence' => $postalLocation['confidence'],
                    'postal_code_location_matches_count' => $postalLocation['matches_count'],
                    'location' => [
                        'postal_code' => $postalCode,
                        'status' => $postalLocation['status'],
                        'source' => $postalLocation['source'],
                        'confidence' => $postalLocation['confidence'],
                    ],
                    'appointment_availability' => 'unknown',
                    'availability_scope' => 'operational_capability_only',
                ],
            ],
        ]);
    }
}

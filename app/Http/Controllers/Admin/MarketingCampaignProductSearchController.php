<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingCampaignProductSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketingCampaign::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:120'],
            'brand' => ['nullable', Rule::enum(LaboratoryBrand::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $q = filled($validated['q'] ?? null) ? trim((string) $validated['q']) : null;
        $brand = $validated['brand'] ?? null;

        if ($q === null && $brand === null) {
            return response()->json(['data' => []]);
        }

        $query = LaboratoryTest::query()
            ->with(['laboratoryTestCategory:id,name'])
            ->when($brand, function ($builder, string $brandValue) {
                $builder->where('brand', $brandValue);
            })
            ->when($q !== null, function ($builder) use ($q) {
                $builder->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%'.$q.'%')
                        ->orWhere('other_name', 'like', '%'.$q.'%')
                        ->orWhere('gda_id', 'like', '%'.$q.'%');
                });
            })
            ->orderBy('name')
            ->limit($validated['limit'] ?? 20);

        $results = $query->get(['id', 'name', 'other_name', 'brand', 'gda_id', 'famedic_price_cents', 'public_price_cents', 'requires_appointment', 'laboratory_test_category_id'])
            ->map(fn (LaboratoryTest $test) => [
                'id' => $test->id,
                'name' => $test->name,
                'other_name' => $test->other_name,
                'brand' => $test->brand?->value ?? $test->brand,
                'brand_label' => $test->brand?->label(),
                'category' => $test->laboratoryTestCategory?->name,
                'gda_id' => $test->gda_id,
                'famedic_price_cents' => $test->famedic_price_cents,
                'public_price_cents' => $test->public_price_cents,
                'requires_appointment' => (bool) $test->requires_appointment,
            ]);

        return response()->json(['data' => $results]);
    }
}

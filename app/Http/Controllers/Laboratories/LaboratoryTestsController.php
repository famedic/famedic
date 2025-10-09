<?php

namespace App\Http\Controllers\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laboratories\IndexLaboratoryTestRequest;
use App\Http\Requests\Laboratories\ShowLaboratoryTestRequest;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\Tracking\Search;
use App\Services\Tracking\ViewContent;
use Inertia\Inertia;

class LaboratoryTestsController extends Controller
{
    public function index(
        IndexLaboratoryTestRequest $request,
        LaboratoryBrand $laboratoryBrand,
    ) {
        $laboratoryTests = LaboratoryTest::algoliaFilter(
            $laboratoryBrand,
            $request->input('query'),
            LaboratoryTestCategory::whereName($request->category)->first()
        )
            ->appends([
                'category' => $request->category,
            ]);

        Search::track(
            searchString: $request->input('query'),
            contentIds: collect($laboratoryTests->items())->pluck('gda_id')->all(),
            source: 'laboratory',
            customProperties: [
                'category' => $request->category,
                'page' => $request->page,
                'brand' => $laboratoryBrand->value,
            ]
        );

        return Inertia::render('LaboratoryTests', [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryBrand),
            'laboratoryTests' => $laboratoryTests,
            'laboratoryTestCategories' => collect([
                LaboratoryTestCategory::find(12),
            ])->filter()->merge(
                LaboratoryTestCategory::whereNotIn('id', [12, 13])->get()
            ),
        ]);
    }

    public function show(ShowLaboratoryTestRequest $request, LaboratoryTest $laboratoryTest)
    {
        $categories = collect([
            \App\Models\LaboratoryTestCategory::find(12),
        ])->filter()->merge(
            \App\Models\LaboratoryTestCategory::whereNotIn('id', [12, 13])->get()
        );

        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            [$laboratoryTest],
            1,
            1,
            1,
            ['path' => url()->current()]
        );

        ViewContent::track(
            productId: $laboratoryTest->gda_id,
            value: $laboratoryTest->famedic_price,
            source: 'laboratory',
            customProperties: [
                'brand' => $laboratoryTest->brand->value,
            ]
        );

        // Get brand image URL
        $brandImage = asset('images/gda/' . $laboratoryTest->brand->imageSrc());

        // Prepare base data
        $data = [
            'laboratoryBrand' => LaboratoryBrand::brandData($laboratoryTest->brand),
            'laboratoryTests' => $paginated,
            'laboratoryTestCategories' => $categories->values(),
        ];

        return Inertia::render('LaboratoryTests', $data);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\LaboratoryTests\CreateLaboratoryTestAction;
use App\Actions\Admin\LaboratoryTests\UpdateLaboratoryTestAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryTests\CreateLaboratoryTestRequest;
use App\Http\Requests\Admin\LaboratoryTests\EditLaboratoryTestRequest;
use App\Http\Requests\Admin\LaboratoryTests\IndexLaboratoryTestRequest;
use App\Http\Requests\Admin\LaboratoryTests\ShowLaboratoryTestRequest;
use App\Http\Requests\Admin\LaboratoryTests\StoreLaboratoryTestRequest;
use App\Http\Requests\Admin\LaboratoryTests\UpdateLaboratoryTestRequest;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Services\Admin\LaboratoryTestAdminStatsService;
use Inertia\Inertia;

class LaboratoryTestController extends Controller
{
    public function index(IndexLaboratoryTestRequest $request)
    {
        $filters = collect($request->only(['search', 'brand', 'category', 'requires_appointment', 'needs_gda_review']))->filter()->all();

        $laboratoryTests = LaboratoryTest::query()
            ->with([
                'laboratoryTestCategory' => fn ($query) => $query->withTrashed(),
            ])
            ->filter($filters)
            ->orderBy('name')
            ->paginate()
            ->withQueryString();

        return Inertia::render('Admin/LaboratoryTests', [
            'laboratoryTests' => $laboratoryTests,
            'filters' => $filters,
            'brands' => LaboratoryBrand::brandsData(),
            'categories' => LaboratoryTestCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(CreateLaboratoryTestRequest $request)
    {
        return Inertia::render('Admin/LaboratoryTestCreation', [
            'brands' => LaboratoryBrand::brandsData(),
            'categories' => LaboratoryTestCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreLaboratoryTestRequest $request, CreateLaboratoryTestAction $action)
    {
        $laboratoryTest = $action(
            brand: LaboratoryBrand::from($request->brand),
            gda_id: $request->gda_id,
            name: $request->name,
            description: $request->description,
            feature_list: $request->feature_list,
            indications: $request->indications,
            other_name: $request->other_name,
            elements: $request->elements,
            common_use: $request->common_use,
            requires_appointment: $request->requires_appointment,
            needs_gda_review: $request->needs_gda_review,
            gda_review_note: $request->gda_review_note,
            public_price: (float) $request->public_price,
            famedic_price: (float) $request->famedic_price,
            laboratory_test_category_id: $request->laboratory_test_category_id,
        );

        return redirect()->route('admin.laboratory-tests.show', ['laboratory_test' => $laboratoryTest])
            ->flashMessage('Prueba de laboratorio creada exitosamente');
    }

    public function show(
        ShowLaboratoryTestRequest $request,
        LaboratoryTest $laboratoryTest,
        LaboratoryTestAdminStatsService $statsService,
    ) {
        return Inertia::render('Admin/LaboratoryTest', [
            'laboratoryTest' => $laboratoryTest->load([
                'laboratoryTestCategory' => fn ($query) => $query->withTrashed(),
            ]),
            'stats' => $statsService->forTest($laboratoryTest),
            'brands' => LaboratoryBrand::brandsData(),
            'categories' => LaboratoryTestCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(EditLaboratoryTestRequest $request, LaboratoryTest $laboratoryTest)
    {
        return Inertia::render('Admin/LaboratoryTestEdit', [
            'laboratoryTest' => $laboratoryTest->load(['laboratoryTestCategory']),
            'brands' => LaboratoryBrand::brandsData(),
            'categories' => LaboratoryTestCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateLaboratoryTestRequest $request, LaboratoryTest $laboratoryTest, UpdateLaboratoryTestAction $action)
    {
        $action(
            laboratoryTest: $laboratoryTest,
            brand: LaboratoryBrand::from($request->brand),
            gda_id: $request->gda_id,
            name: $request->name,
            description: $request->description,
            feature_list: $request->feature_list,
            indications: $request->indications,
            other_name: $request->other_name,
            elements: $request->elements,
            common_use: $request->common_use,
            requires_appointment: $request->requires_appointment,
            needs_gda_review: $request->needs_gda_review,
            gda_review_note: $request->gda_review_note,
            public_price: (float) $request->public_price,
            famedic_price: (float) $request->famedic_price,
            laboratory_test_category_id: $request->laboratory_test_category_id,
        );

        return redirect()->route('admin.laboratory-tests.show', ['laboratory_test' => $laboratoryTest])
            ->flashMessage('Prueba de laboratorio actualizada exitosamente');
    }
}

<?php

namespace App\Http\Controllers\Laboratories;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTestCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryBrandSelectionController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render(
            'LaboratoryBrandSelection',
            [
                'states' => LaboratoryStore::select('state')
                    ->distinct()
                    ->orderBy('state')
                    ->pluck('state')
                    ->toArray(),
                'laboratoryTestCategories' => collect([
                    LaboratoryTestCategory::find(12),
                ])->filter()->merge(
                    LaboratoryTestCategory::whereNotIn('id', [12, 13])->get()
                )->values()->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                    ];
                }),
            ]
        );
    }
}

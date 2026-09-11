<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryStores\IndexLaboratoryStoreRequest;
use App\Http\Resources\LaboratoryStoreDirectoryResource;
use App\Services\LaboratoryStores\LaboratoryStoreSearchQuery;
use Inertia\Inertia;

class LaboratoryStoreController extends Controller
{
    public function index(IndexLaboratoryStoreRequest $request)
    {
        $filters = $request->filters();
        $search = new LaboratoryStoreSearchQuery($filters, $request->serviceTypes());
        $stores = $search->stores();

        return Inertia::render('LaboratoryStores', [
            'laboratoryStores' => LaboratoryStoreDirectoryResource::collection($stores)->resolve($request),
            'filters' => $filters,
            'states' => $search->states(),
            'municipalities' => $search->municipalities(),
            'capabilities' => $search->capabilities(),
            'services' => $search->services(),
            'total' => $search->total(),
            'filtered_total' => $search->filteredTotal(),
        ]);
    }
}

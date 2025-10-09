<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryStore;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryStoreController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('LaboratoryStores', [
            'laboratoryStores' => LaboratoryStore::filter($request->only('brand', 'state'))->get(),
            'states' => LaboratoryStore::select('state')
                ->distinct()
                ->orderBy('state')
                ->pluck('state')
                ->toArray()
        ]);
    }
}

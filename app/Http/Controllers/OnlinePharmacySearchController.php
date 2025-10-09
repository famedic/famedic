<?php

namespace App\Http\Controllers;

use App\Actions\OnlinePharmacy\FetchCategoriesAction;
use App\Actions\OnlinePharmacy\FetchProductsAction;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacySearchRequest;
use App\Services\Tracking\Search;
use Inertia\Inertia;

class OnlinePharmacySearchController extends Controller
{
    public function __invoke(
        OnlinePharmacySearchRequest $request,
        FetchProductsAction $fetchProductsAction,
        FetchCategoriesAction $fetchCategoriesAction,
    ) {
        $productsResponse = $fetchProductsAction(
            search: $request->search,
            category: $request->category,
            page: $request->page
        );

        Search::track(
            searchString: $request->search,
            contentIds: collect($productsResponse['results'])->pluck('id')->all(),
            source: 'online-pharmacy',
            customProperties: [
                'category' => $request->category,
                'page'     => $request->page,
            ]
        );

        return Inertia::render('OnlinePharmacySearch', [
            'vitauProducts' => collect($productsResponse['results']),
            'vitauCategories' => $fetchCategoriesAction(),
            'previousPage' => $productsResponse['previous'] ? (($request->get('page', 1)) - 1) : null,
            'nextPage' => $productsResponse['next'] ? (($request->get('page', 1)) + 1) : null
        ]);
    }
}

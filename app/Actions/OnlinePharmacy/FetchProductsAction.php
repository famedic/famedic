<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchProductsAction
{
    const LIMIT = 15;

    public function __invoke(?string $search = '', ?string $category = '', ?int $page = 1)
    {
        $cacheKey = "vitau_products:search={$search}:category={$category}:page={$page}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = config('services.vitau.url') . 'products';

        $response = Http::withHeaders([
            'x-api-key' => config('services.vitau.key'),
        ])->get($url, [
            'limit' => self::LIMIT,
            'search' => $search,
            'page' => $page,
            'has_shortage' => false,
            'is_active' => true,
            'base__category' => $category,
        ]);

        if ($response->failed()) {
            Log::error('Failed to fetch products', ['search' => $search, 'category' => $category, 'page' => $page, 'response' => $response->json()]);
            throw new Exception('Failed to fetch products from API.');
        }

        $products = $response->json();
        Cache::put($cacheKey, $products, now()->addDay());

        return $products;
    }
}

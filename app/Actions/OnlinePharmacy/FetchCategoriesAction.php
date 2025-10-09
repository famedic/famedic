<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class FetchCategoriesAction
{
    public function __invoke(): Collection
    {
        $url = config('services.vitau.url') . 'categories';

        $response = Http::withHeaders([
            'x-api-key' => config('services.vitau.key'),
        ])->get($url, [
            'available' => true,
            'ordering' => 'name'
        ]);


        if ($response->failed()) {
            throw new Exception();
        }

        return collect($response->json());
    }
}

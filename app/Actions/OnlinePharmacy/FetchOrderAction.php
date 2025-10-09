<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchOrderAction
{
    private FetchAuthenticationTokenAction $fetchAuthenticationTokenAction;

    public function __construct(FetchAuthenticationTokenAction $fetchAuthenticationTokenAction)
    {
        $this->fetchAuthenticationTokenAction = $fetchAuthenticationTokenAction;
    }

    public function __invoke($orderId): array
    {
        $cacheKey = "vitau_order:$orderId";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = config('services.vitau.url') . "orders/$orderId";

        $response = Http::withToken(($this->fetchAuthenticationTokenAction)())
            ->withHeaders([
                'x-api-key' => config('services.vitau.key'),
            ])
            ->get($url);

        if ($response->failed()) {
            Log::error('Failed to fetch order', ['orderId' => $orderId, 'response' => $response->json()]);
            throw new Exception('Failed to fetch order from API.');
        }

        $orderData = $response->json();
        Cache::put($cacheKey, $orderData, now()->addDay());

        return $orderData;
    }
}

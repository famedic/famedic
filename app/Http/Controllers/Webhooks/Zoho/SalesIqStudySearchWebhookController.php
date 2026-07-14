<?php

namespace App\Http\Controllers\Webhooks\Zoho;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\Zoho\SalesIqStudySearchRequest;
use App\Services\Zoho\SalesIqStudySearchService;
use Illuminate\Http\JsonResponse;

class SalesIqStudySearchWebhookController extends Controller
{
    public function __invoke(
        SalesIqStudySearchRequest $request,
        SalesIqStudySearchService $service,
    ): JsonResponse {
        $result = $service->handle($request->validated());

        // No exponer `reason` al Zobot (solo se usa internamente / en evento sanitizado).
        unset($result['reason']);

        return response()->json($result);
    }
}

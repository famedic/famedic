<?php

namespace App\Http\Controllers\Webhooks\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\SalesIqWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesIqEventWebhookController extends Controller
{
    public function __invoke(Request $request, SalesIqWebhookService $service): JsonResponse
    {
        $service->recordFromRequest($request, 'bot_intent');

        return response()->json(['ok' => true]);
    }
}

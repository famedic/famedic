<?php

namespace App\Http\Controllers\Webhooks\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\SalesIqWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesIqConversationClosedWebhookController extends Controller
{
    public function __invoke(Request $request, SalesIqWebhookService $service): JsonResponse
    {
        $service->recordFromRequest($request, 'conversation_closed');

        return response()->json(['ok' => true]);
    }
}

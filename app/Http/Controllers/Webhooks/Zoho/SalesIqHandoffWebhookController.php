<?php

namespace App\Http\Controllers\Webhooks\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\SalesIqWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesIqHandoffWebhookController extends Controller
{
    public function __invoke(Request $request, SalesIqWebhookService $service): JsonResponse
    {
        $payload = $request->all();
        $payload['event_type'] = $payload['event_type'] ?? 'handoff';

        $service->record('handoff', $payload);

        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Integrations\AtixPhoneLookupRequest;
use App\Services\Api\V1\Integrations\AtixPhoneLookupService;
use Illuminate\Http\JsonResponse;
use App\Services\Api\V1\Integrations\AtixAuditRecorder;

final class AtixPhoneLookupController extends Controller
{
    public function __construct(
        private readonly AtixPhoneLookupService $service,
        private readonly AtixAuditRecorder $audit,
    ) {}

    public function store(AtixPhoneLookupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = $this->service->lookup($data);
        $this->audit->record($request, $response->getStatusCode() < 300 ? 'success' : 'failure', $response->getStatusCode(), $response->getStatusCode() >= 400 ? (string) data_get($response->getData(true), 'error.code') : null, [
                'channel' => (string) ($data['channel'] ?? 'unknown'),
                'audience' => (string) ($data['audience'] ?? 'unknown'),
                'event_hash' => hash('sha256', (string) ($data['channel_event_id'] ?? 'unknown')),
                'account_match' => $response->getStatusCode() === 202 ? 'anti_enumerated' : 'not_processed',
                'personalization_allowed' => (bool) data_get($data, 'personalization.allowed', false),
        ]);
        return $response;
    }
}

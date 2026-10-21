<?php

namespace App\Services\Api\V1\Integrations;

use App\Services\Api\V1\Audit\AuditActorResolver;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Services\Api\V1\Audit\AuditEventWriter;
use Illuminate\Http\Request;

final class AtixAuditRecorder
{
    public function __construct(
        private readonly AuditEventWriter $writer,
        private readonly AuditActorResolver $actors,
    ) {}

    public function record(Request $request, string $outcome, int $status, ?string $code, array $metadata = []): void
    {
        $integrationHash = (string) $request->attributes->get('atix.integration_key_hash', 'unknown');
        $this->writer->write([
            'event_name' => AuditEventDefinitions::EVENT_ATIX_PHONE_LOOKUP,
            'outcome' => $outcome,
            'actor' => $this->actors->resolvePublic('atix.phone_lookup', $integrationHash !== '' ? $integrationHash : 'unknown'),
            'http_status' => $status,
            'error_code' => $code,
            'metadata' => array_merge([
                'integration' => 'atix_sandbox',
                'outcome_class' => $outcome,
            ], $metadata),
        ]);
    }
}

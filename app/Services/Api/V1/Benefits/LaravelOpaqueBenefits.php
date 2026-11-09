<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\OpaqueBenefitWorkflow;
use Illuminate\Http\Request;

final class LaravelOpaqueBenefits
{
    public function __construct(private BenefitAccess $access) {}

    public function snapshot(Request $request): array
    {
        if (array_diff(array_keys($request->query()), ['service_line']) || $request->query('service_line') !== 'medical_services') {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        $actor = $this->access->actor($request, 'benefits:read');
        $store = new EloquentBenefitStateStore($actor);
        $workflow = new OpaqueBenefitWorkflow($store, $this->access->authorizer($request));

        return $store->transaction(fn () => $workflow->preview($actor, $store->cartReference(), now()->timestamp));
    }

    public function mutate(Request $request, string $operation, ?string $application = null): array
    {
        if (! $request->isJson()) {
            throw new BenefitFailure('VALIDATION_ERROR');
        }
        $actor = $this->access->actor($request, 'benefits:'.$operation);
        $body = $request->json()->all();
        if ($operation === 'remove') {
            if (array_key_exists('application_ref', $body)) {
                throw new BenefitFailure('VALIDATION_ERROR');
            }
            $body['application_ref'] = $application;
        }
        if (! isset($body['confirmation_ref'])) {
            throw new BenefitFailure('CONFIRMATION_REQUIRED');
        }
        $store = new EloquentBenefitStateStore($actor);
        $workflow = new OpaqueBenefitWorkflow($store, $this->access->authorizer($request));

        return $store->transaction(function () use ($store, $workflow, $actor, $body, $operation, $request) {
            $approvals = app(BenefitApprovals::class);
            if ($approvals instanceof CertifiedBenefitApprovals && is_string($body['confirmation_ref'] ?? null) && \App\Domain\Akubica\Benefits\OpaqueReference::valid($body['confirmation_ref'], 'cnf')) {
                $receipt = $store->get('confirmations', \App\Domain\Akubica\Benefits\OpaqueReference::key($body['confirmation_ref'], 'cnf'));
                $text = app(ConsentTextRegistry::class)->active($approvals->textVersion());
                if ($receipt && (! $text || ($receipt['text_version'] ?? null) !== $text->version || ($receipt['text_digest'] ?? null) !== $text->digest)) {
                    throw new BenefitFailure('CONFIRMATION_CONTEXT_CHANGED');
                }
            }

            return $workflow->$operation($actor, $body, (string) $request->header('Idempotency-Key', ''), now()->timestamp);
        });
    }
}

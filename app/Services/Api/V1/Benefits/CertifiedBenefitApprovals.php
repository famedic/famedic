<?php

namespace App\Services\Api\V1\Benefits;

/** No default operational approval. Explicit certification, registry text and contract rollout required. */
final class CertifiedBenefitApprovals implements BenefitApprovals
{
    public function api(): bool
    {
        return config('akubica_connectors.contract.accepted') === true && config('akubica_connectors.benefits_api.enabled') === true && config('akubica_connectors.benefits_api.certified') === true && $this->consent();
    }

    public function consent(): bool
    {
        return config('akubica_connectors.contract.accepted') === true && config('akubica_connectors.consent.enabled') === true && config('akubica_connectors.consent.certified') === true && app(ConsentTextRegistry::class)->active($this->textVersion()) !== null;
    }

    public function financial(): bool
    {
        return $this->consent() && config('akubica_connectors.financial.enabled') === true && config('akubica_connectors.financial.certified') === true && config('akubica_connectors.financial.legacy_writers_coordinated') === true;
    }

    public function textVersion(): ?string
    {
        $v = config('akubica_connectors.consent.text_version');

        return is_string($v) && $v !== '' ? $v : null;
    }
}

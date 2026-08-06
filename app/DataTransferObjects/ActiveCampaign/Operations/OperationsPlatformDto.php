<?php

namespace App\DataTransferObjects\ActiveCampaign\Operations;

/**
 * Payload completo Phase 2 — fuente única para UI y futuro AI Assistant.
 */
class OperationsPlatformDto extends OperationsDto
{
    /**
     * @param  list<array<string, mixed>>  $executive
     * @param  list<array<string, mixed>>  $funnel
     * @param  list<array<string, mixed>>  $laboratories
     * @param  array<string, mixed>  $memberships
     * @param  array<string, mixed>  $purchases
     * @param  list<array<string, mixed>>  $automations
     * @param  array<string, mixed>  $contactHealth
     * @param  list<array<string, mixed>>  $alerts
     * @param  array<string, mixed>  $analytics
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>|null  $searchResults
     */
    public function __construct(
        public readonly array $executive,
        public readonly array $funnel,
        public readonly array $laboratories,
        public readonly array $memberships,
        public readonly array $purchases,
        public readonly array $automations,
        public readonly array $contactHealth,
        public readonly array $alerts,
        public readonly array $analytics,
        public readonly array $filters,
        public readonly ?array $searchResults = null,
        public readonly string $generatedAt = '',
    ) {}

    public function toArray(): array
    {
        return [
            'executive' => $this->executive,
            'funnel' => $this->funnel,
            'laboratories' => $this->laboratories,
            'memberships' => $this->memberships,
            'purchases' => $this->purchases,
            'automations' => $this->automations,
            'contact_health' => $this->contactHealth,
            'alerts' => $this->alerts,
            'analytics' => $this->analytics,
            'filters' => $this->filters,
            'search_results' => $this->searchResults,
            'generated_at' => $this->generatedAt,
            'ai_context' => [
                'domain' => 'activecampaign_operations',
                'version' => 2,
                'questions_supported' => [
                    'top_laboratory_sales',
                    'failed_automations',
                    'at_risk_contacts',
                    'owner_conversions',
                    'integration_errors',
                ],
            ],
        ];
    }
}

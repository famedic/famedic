<?php

namespace App\DTOs\CustomerIntelligence;

final class CustomerHealthScoreData
{
    /**
     * @param  list<string>  $positiveSignals
     * @param  list<string>  $negativeSignals
     * @param  list<string>  $recommendedActions
     * @param  array<string, float>  $probabilities
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $healthScore,
        public readonly string $band,
        public readonly string $bandLabel,
        public readonly int $leadScore,
        public readonly array $positiveSignals,
        public readonly array $negativeSignals,
        public readonly array $probabilities,
        public readonly array $recommendedActions,
        public readonly string $persona,
        public readonly float $ltv,
        public readonly ?int $daysSincePurchase,
        public readonly ?int $daysSinceActivity,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'health_score' => $this->healthScore,
            'band' => $this->band,
            'band_label' => $this->bandLabel,
            'lead_score' => $this->leadScore,
            'positive_signals' => $this->positiveSignals,
            'negative_signals' => $this->negativeSignals,
            'probabilities' => $this->probabilities,
            'recommended_actions' => $this->recommendedActions,
            'persona' => $this->persona,
            'ltv' => $this->ltv,
            'ltv_formatted' => '$'.number_format($this->ltv, 0).' MXN',
            'days_since_purchase' => $this->daysSincePurchase,
            'days_since_activity' => $this->daysSinceActivity,
        ];
    }

    public static function classify(int $score): array
    {
        return match (true) {
            $score >= 81 => ['band' => 'excellent', 'label' => 'Excelente', 'tone' => 'green'],
            $score >= 61 => ['band' => 'good', 'label' => 'Bueno', 'tone' => 'blue'],
            $score >= 41 => ['band' => 'at_risk', 'label' => 'En Riesgo', 'tone' => 'orange'],
            $score >= 21 => ['band' => 'critical', 'label' => 'Crítico', 'tone' => 'red'],
            default => ['band' => 'lost', 'label' => 'Perdido', 'tone' => 'slate'],
        };
    }
}

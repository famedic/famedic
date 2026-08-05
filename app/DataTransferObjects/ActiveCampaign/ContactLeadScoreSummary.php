<?php

namespace App\DataTransferObjects\ActiveCampaign;

/**
 * Resumen tipado del lead score (total + clasificación).
 * Compatible con snapshot->leadScoreTotal().
 */
class ContactLeadScoreSummary
{
    public const CLASS_EXCELLENT = 'Excelente';

    public const CLASS_GOOD = 'Bueno';

    public const CLASS_RISK = 'En Riesgo';

    public const CLASS_CRITICAL = 'Crítico';

    /**
     * @param  list<ContactScoreData>  $scores
     */
    public function __construct(
        public readonly int $total,
        public readonly ?ContactScoreData $primary,
        public readonly ?string $updatedAt,
        public readonly string $classification,
        public readonly array $scores = [],
    ) {}

    public static function fromScores(array $scores): self
    {
        $total = array_sum(array_map(
            static fn (ContactScoreData $score) => $score->scoreValue,
            $scores
        ));

        $primary = null;
        foreach ($scores as $score) {
            if ($primary === null || $score->scoreValue > $primary->scoreValue) {
                $primary = $score;
            }
        }

        $updatedAt = null;
        foreach ($scores as $score) {
            $candidate = $score->mdate ?? $score->cdate;
            if ($candidate === null) {
                continue;
            }
            if ($updatedAt === null || strcmp($candidate, $updatedAt) > 0) {
                $updatedAt = $candidate;
            }
        }

        return new self(
            total: $total,
            primary: $primary,
            updatedAt: $updatedAt,
            classification: self::classify($total),
            scores: array_values($scores),
        );
    }

    public static function classify(int $total): string
    {
        return match (true) {
            $total >= 80 => self::CLASS_EXCELLENT,
            $total >= 50 => self::CLASS_GOOD,
            $total >= 20 => self::CLASS_RISK,
            default => self::CLASS_CRITICAL,
        };
    }

    /**
     * @return array{
     *     total: int,
     *     primary: array<string, mixed>|null,
     *     updated_at: string|null,
     *     classification: string,
     *     scores: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'primary' => $this->primary?->toArray(),
            'updated_at' => $this->updatedAt,
            'classification' => $this->classification,
            'scores' => array_map(
                static fn (ContactScoreData $s) => $s->toArray(),
                $this->scores
            ),
        ];
    }
}

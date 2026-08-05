<?php

namespace App\DataTransferObjects\ActiveCampaign;

use Illuminate\Support\Carbon;

class ActiveCampaignContactSnapshot
{
    /**
     * @param  list<ContactTagData>  $tags
     * @param  list<ContactListData>  $lists
     * @param  list<ContactFieldData>  $fields  Todos los fieldValues enriquecidos
     * @param  list<ContactFieldData>  $relevantFields  Subconjunto visible (sin internos)
     * @param  list<ContactAutomationData>  $automations
     * @param  list<ContactActivityData>  $activities
     * @param  list<ContactScoreData>  $scores
     * @param  array{
     *     city?: string|null,
     *     state?: string|null,
     *     country?: string|null,
     *     country2?: string|null,
     *     zip?: string|null,
     *     lat?: string|null,
     *     lon?: string|null,
     *     ip?: string|null,
     *     tz?: string|null
     * }|null  $location
     */
    public function __construct(
        public readonly int $acContactId,
        public readonly ?int $customerId,
        public readonly ?string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?array $location,
        public readonly array $tags,
        public readonly array $lists,
        public readonly array $fields,
        public readonly array $automations,
        public readonly array $activities,
        public readonly array $scores,
        public readonly ?ContactActivityData $lastActivity,
        public readonly Carbon $mirroredAt,
        public readonly bool $fromCache = false,
        public readonly array $relevantFields = [],
        public readonly ?ContactOwnerData $owner = null,
        public readonly ?ContactEngagementData $engagement = null,
        public readonly ?ContactLeadScoreSummary $leadScore = null,
    ) {}

    public function leadScoreTotal(): int
    {
        if ($this->leadScore !== null) {
            return $this->leadScore->total;
        }

        return array_sum(array_map(
            static fn (ContactScoreData $score) => $score->scoreValue,
            $this->scores
        ));
    }

    public function leadScoreSummary(): ContactLeadScoreSummary
    {
        return $this->leadScore ?? ContactLeadScoreSummary::fromScores($this->scores);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lead = $this->leadScoreSummary();

        return [
            'ac_contact_id' => $this->acContactId,
            'customer_id' => $this->customerId,
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'location' => $this->location,
            'owner' => $this->owner?->toArray(),
            'tags' => array_map(static fn (ContactTagData $t) => $t->toArray(), $this->tags),
            'lists' => array_map(static fn (ContactListData $l) => $l->toArray(), $this->lists),
            'fields' => array_map(static fn (ContactFieldData $f) => $f->toArray(), $this->fields),
            'relevant_fields' => array_map(
                static fn (ContactFieldData $f) => $f->toArray(),
                $this->relevantFields !== [] ? $this->relevantFields : $this->fields
            ),
            'automations' => array_map(static fn (ContactAutomationData $a) => $a->toArray(), $this->automations),
            'activities' => array_map(static fn (ContactActivityData $a) => $a->toArray(), $this->activities),
            'scores' => array_map(static fn (ContactScoreData $s) => $s->toArray(), $this->scores),
            // BC: entero total
            'lead_score' => $this->leadScoreTotal(),
            // Fase 2: detalle
            'lead_score_detail' => $lead->toArray(),
            'engagement' => ($this->engagement ?? ContactEngagementData::unavailable())->toArray(),
            'last_activity' => $this->lastActivity?->toArray(),
            'mirrored_at' => $this->mirroredAt->toIso8601String(),
            'from_cache' => $this->fromCache,
        ];
    }

    public function withFromCache(bool $fromCache = true): self
    {
        return new self(
            acContactId: $this->acContactId,
            customerId: $this->customerId,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            phone: $this->phone,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            location: $this->location,
            tags: $this->tags,
            lists: $this->lists,
            fields: $this->fields,
            automations: $this->automations,
            activities: $this->activities,
            scores: $this->scores,
            lastActivity: $this->lastActivity,
            mirroredAt: $this->mirroredAt,
            fromCache: $fromCache,
            relevantFields: $this->relevantFields,
            owner: $this->owner,
            engagement: $this->engagement,
            leadScore: $this->leadScore,
        );
    }
}

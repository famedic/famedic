<?php

namespace App\Http\Resources\Admin\CustomerIntelligence;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class CustomerJourneyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => $row['id'] ?? null,
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'avatar' => $row['avatar'] ?? null,
            'registered_at' => $row['registered_at'] ?? null,
            'last_activity_at' => $row['last_activity_at'] ?? null,
            'last_stage' => $row['last_stage'] ?? null,
            'last_stage_label' => $row['last_stage_label'] ?? null,
            'days_stalled' => $row['days_stalled'] ?? null,
            'lead_score' => $row['lead_score'] ?? null,
            'ai_probability' => $row['ai_probability'] ?? null,
            'risk_segment' => $row['risk_segment'] ?? null,
            'show_url' => $row['show_url'] ?? null,
        ];
    }
}

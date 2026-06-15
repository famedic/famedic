<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryCheckoutDraft;
use App\Support\Api\V1\LaboratoryAppointmentSupport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryAppointment */
class LaboratoryAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $support = app(LaboratoryAppointmentSupport::class);
        $scheduledAt = $this->appointment_date ?? $this->callback_availability_starts_at;

        $data = [
            'id' => $this->id,
            'brand' => $this->brand->value,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            'status' => $support->resolveStatus($this->resource),
            'patient_full_name' => $this->patient_full_name,
        ];

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $this->customer_id)
            ->where('laboratory_brand', $this->brand)
            ->with(['contact', 'address'])
            ->first();

        if ($draft?->contact) {
            $data['contact'] = [
                'id' => $draft->contact->id,
                'full_name' => trim($draft->contact->full_name),
            ];
        } elseif ($this->patient_full_name) {
            $data['contact'] = [
                'id' => null,
                'full_name' => $this->patient_full_name,
            ];
        }

        if ($draft?->address) {
            $data['address'] = (new AddressResource($draft->address))->resolve($request);
        }

        return $data;
    }
}

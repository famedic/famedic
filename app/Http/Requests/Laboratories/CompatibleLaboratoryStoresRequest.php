<?php

namespace App\Http\Requests\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Support\LaboratoryRequirements\GeoPoint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompatibleLaboratoryStoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/', 'prohibited_if:clear_postal_code,true'],
            'clear_postal_code' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function brand(): LaboratoryBrand
    {
        return LaboratoryBrand::from((string) $this->validated('brand'));
    }

    public function postalCode(): ?string
    {
        $postalCode = $this->validated('postal_code') ?? null;

        return is_string($postalCode) && $postalCode !== '' ? $postalCode : null;
    }

    public function shouldClearPostalCode(): bool
    {
        return (bool) ($this->validated('clear_postal_code') ?? false);
    }

    public function location(): ?GeoPoint
    {
        if (! $this->filled('latitude') || ! $this->filled('longitude')) {
            return null;
        }

        return new GeoPoint(
            latitude: round((float) $this->input('latitude'), 7),
            longitude: round((float) $this->input('longitude'), 7),
        );
    }

    public function requestedDate(): ?CarbonInterface
    {
        if (! $this->filled('date')) {
            return null;
        }

        return CarbonImmutable::parse($this->date('date')->toDateString().' 12:00:00', 'America/Mexico_City');
    }
}

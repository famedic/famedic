<?php

namespace App\Services;

use App\Models\CouponConcept;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CouponConceptService
{
    /**
     * @return array<string, list<string|\Illuminate\Validation\Rules\Exists>>
     */
    public function validationRules(): array
    {
        return [
            'coupon_concept_id' => ['nullable', 'integer', Rule::exists('coupon_concepts', 'id')],
            'concept_other' => ['nullable', 'string', 'max:255'],
            'concept_is_other' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{coupon_concept_id: ?int, concept_other: ?string}
     */
    public function resolveConceptPayload(array $data): array
    {
        $conceptIsOther = filter_var($data['concept_is_other'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $conceptOther = isset($data['concept_other']) ? trim((string) $data['concept_other']) : '';

        if ($conceptIsOther) {
            try {
                Validator::make(
                    ['concept_other' => $conceptOther],
                    ['concept_other' => ['required', 'string', 'max:255']],
                    ['concept_other.required' => 'Indica el concepto personalizado.']
                )->validate();
            } catch (ValidationException $e) {
                throw $e;
            }

            $concept = CouponConcept::findOrCreateByTitle($conceptOther);

            return [
                'coupon_concept_id' => $concept->id,
                'concept_other' => $conceptOther,
            ];
        }

        $conceptId = isset($data['coupon_concept_id']) && $data['coupon_concept_id'] !== null && $data['coupon_concept_id'] !== ''
            ? (int) $data['coupon_concept_id']
            : null;

        if ($conceptId !== null) {
            return [
                'coupon_concept_id' => $conceptId,
                'concept_other' => null,
            ];
        }

        return [
            'coupon_concept_id' => null,
            'concept_other' => null,
        ];
    }
}

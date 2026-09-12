<?php

namespace App\Services\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaignCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketingCampaignTargetPayloadValidator
{
    /**
     * Valida y normaliza el payload de destino.
     *
     * Para `product`, acepta únicamente `laboratory_test_id` desde el cliente y
     * devuelve una representación canónica con la marca derivada del modelo.
     *
     * Para `collection`, el contexto de campaña es obligatorio.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(
        MarketingCampaignTargetType $targetType,
        array $payload,
        ?int $marketingCampaignId = null,
    ): array {
        if ($targetType === MarketingCampaignTargetType::Collection) {
            if ($marketingCampaignId === null) {
                throw ValidationException::withMessages([
                    'marketing_campaign_id' => 'El destino de colección requiere el contexto de la campaña.',
                ]);
            }
        }

        // Nunca confiar en una marca enviada por el frontend para productos.
        if ($targetType === MarketingCampaignTargetType::Product) {
            unset($payload['brand']);
        }

        $rules = match ($targetType) {
            MarketingCampaignTargetType::Brand => [
                'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            ],
            MarketingCampaignTargetType::Category => [
                'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
                'laboratory_test_category_id' => [
                    'required',
                    'integer',
                    Rule::exists((new LaboratoryTestCategory)->getTable(), 'id')
                        ->whereNull('deleted_at'),
                ],
            ],
            MarketingCampaignTargetType::Product => [
                'laboratory_test_id' => [
                    'required',
                    'integer',
                    Rule::exists((new LaboratoryTest)->getTable(), 'id')
                        ->whereNull('deleted_at'),
                ],
            ],
            MarketingCampaignTargetType::Collection => [
                'marketing_campaign_collection_id' => [
                    'required',
                    'integer',
                    Rule::exists((new MarketingCampaignCollection)->getTable(), 'id')
                        ->whereNull('deleted_at')
                        ->where('marketing_campaign_id', $marketingCampaignId),
                ],
            ],
        };

        $validated = Validator::make($payload, $rules, [
            '*.required' => 'El destino seleccionado requiere este valor.',
            '*.exists' => 'El destino seleccionado no existe o no pertenece a la campaña.',
        ])->validate();

        $unexpectedKeys = array_diff(array_keys($payload), array_keys($rules));
        if ($unexpectedKeys !== []) {
            throw ValidationException::withMessages([
                'target_payload' => 'El payload contiene campos no permitidos.',
            ]);
        }

        if ($targetType === MarketingCampaignTargetType::Product) {
            $test = LaboratoryTest::query()->findOrFail($validated['laboratory_test_id']);
            $brand = $test->brand instanceof LaboratoryBrand
                ? $test->brand->value
                : (string) $test->brand;

            return [
                'laboratory_test_id' => (int) $test->id,
                'brand' => $brand,
            ];
        }

        return $validated;
    }
}

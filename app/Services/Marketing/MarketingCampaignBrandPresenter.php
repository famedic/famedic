<?php

namespace App\Services\Marketing;

use App\Enums\LaboratoryBrand;

class MarketingCampaignBrandPresenter
{
    /**
     * @return array{value: string, label: string, logo_url: string, imageSrc: string, states: list<string>}
     */
    public function present(LaboratoryBrand $brand): array
    {
        // Root-relative como el catálogo público (/images/gda/...), evita APP_URL incorrecto en Docker.
        $imageSrc = $brand->imageSrc();

        return [
            'value' => $brand->value,
            'label' => $brand->label(),
            'logo_url' => '/images/gda/'.$imageSrc,
            'imageSrc' => $imageSrc,
            'states' => $brand->states(),
            'catalog_url' => route('laboratory-tests', [
                'laboratory_brand' => $brand->value,
            ]),
            'stores_url' => route('laboratory-stores.index', [
                'brand' => $brand->value,
            ]),
        ];
    }
}

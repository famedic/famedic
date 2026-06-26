<?php

namespace App\Http\Requests\Concerns;

use App\Enums\LaboratoryBrand;

trait ResolvesLaboratoryBrand
{
    protected function resolveLaboratoryBrand(): ?LaboratoryBrand
    {
        $brand = $this->route('laboratory_brand');

        if ($brand instanceof LaboratoryBrand) {
            return $brand;
        }

        if (is_string($brand)) {
            return LaboratoryBrand::tryFrom($brand);
        }

        return null;
    }
}

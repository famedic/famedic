<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartMemberships;

use App\Http\Requests\Concerns\ResolvesLaboratoryBrand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class DestroyLaboratoryCartMembershipRequest extends FormRequest
{
    use ResolvesLaboratoryBrand;

    public function authorize(): bool
    {
        return $this->user()?->customer !== null
            && $this->resolveLaboratoryBrand() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(
            'No pudimos quitar la membresía del carrito. Actualiza la página e inténtalo de nuevo.',
        );
    }
}

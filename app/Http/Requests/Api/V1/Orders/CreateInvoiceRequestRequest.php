<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Services\Api\V1\Leo\LeoOpaqueIdentifiers;
use App\Support\Api\V1\LeoConsumer;

class CreateInvoiceRequestRequest extends ApiFormRequest
{
    /** Internal tax profile id resolved from Leo's opaque reference (rev7 LeoInvoiceRequestCreate, L13b). */
    private ?int $leoTaxProfileId = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (LeoConsumer::is($this)) {
            // RC2 Batch 2 · A3 (rev7 L13): Leo sends only the opaque id from GET /user/tax-profiles. An integer
            // (internal id), unknown, malformed, foreign or deleted reference is 422 on tax_profile_id, with the
            // same message, without effect and without revealing whether the profile exists.
            return [
                'tax_profile_id' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                    $customerId = (int) $this->user()?->customer?->id;
                    $this->leoTaxProfileId = is_string($value) && $customerId > 0
                        ? app(LeoOpaqueIdentifiers::class)->resolve($customerId, LeoOpaqueIdentifiers::TAX_PROFILE, $value)
                        : null;
                    if ($this->leoTaxProfileId === null) {
                        $fail('El perfil fiscal no es válido.');
                    }
                }],
                'cfdi_use' => ['nullable', 'string', 'max:10'],
            ];
        }

        return [
            'tax_profile_id' => ['required', 'integer', 'min:1'],
            'cfdi_use' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Internal tax profile id for the ownership lookup (Leo: resolved opaque reference; native: integer id). */
    public function taxProfileId(): int
    {
        return LeoConsumer::is($this) ? (int) $this->leoTaxProfileId : (int) $this->validated('tax_profile_id');
    }
}

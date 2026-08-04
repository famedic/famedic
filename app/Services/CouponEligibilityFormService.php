<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CouponEligibilityFormService
{
    /**
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        return [
            'validity_mode' => ['required', Rule::in(['open', 'configured'])],
            'minimum_purchase_mode' => ['required', Rule::in(['none', 'required'])],
            'valid_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'min_purchase_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{valid_from: ?Carbon, expires_at: ?Carbon, min_purchase_cents: ?int}
     */
    public function resolveAttributes(array $data): array
    {
        $validated = Validator::make($data, $this->validationRules())->validate();

        $this->assertModeConstraints($validated);

        return $this->attributesFromValidated($validated);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertModeConstraints(array $data): void
    {
        $errors = [];

        if (($data['validity_mode'] ?? '') === 'configured') {
            $from = $data['valid_from'] ?? null;
            $expires = $data['expires_at'] ?? null;
            $hasFrom = $from !== null && $from !== '';
            $hasExpires = $expires !== null && $expires !== '';

            if (! $hasFrom && ! $hasExpires) {
                $errors['valid_from'] = 'Indica al menos una fecha de vigencia o elige «Sin vigencia definida».';
            } elseif ($hasFrom && $hasExpires && Carbon::parse((string) $expires)->lt(Carbon::parse((string) $from))) {
                $errors['expires_at'] = 'La fecha de vencimiento debe ser igual o posterior a «Disponible desde».';
            }
        }

        if (($data['minimum_purchase_mode'] ?? '') === 'required') {
            $cents = $data['min_purchase_cents'] ?? null;
            if ($cents === null || $cents === '' || (int) $cents <= 0) {
                $errors['min_purchase_cents'] = 'Indica un monto de compra mínima mayor a cero.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{valid_from: ?Carbon, expires_at: ?Carbon, min_purchase_cents: ?int}
     */
    private function attributesFromValidated(array $data): array
    {
        if (($data['validity_mode'] ?? '') === 'open') {
            $validFrom = null;
            $expiresAt = null;
        } else {
            $validFrom = ! empty($data['valid_from'])
                ? Carbon::parse((string) $data['valid_from'])
                : null;
            $expiresAt = ! empty($data['expires_at'])
                ? Carbon::parse((string) $data['expires_at'])
                : null;
        }

        $minPurchaseCents = ($data['minimum_purchase_mode'] ?? '') === 'none'
            ? null
            : (int) $data['min_purchase_cents'];

        return [
            'valid_from' => $validFrom,
            'expires_at' => $expiresAt,
            'min_purchase_cents' => $minPurchaseCents,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hasPlatformWideAssignmentRestrictions(array $data): bool
    {
        $validityMode = (string) ($data['validity_mode'] ?? 'open');
        $hasValidity = $validityMode === 'configured'
            && (filled($data['valid_from'] ?? null) || filled($data['expires_at'] ?? null));

        $minMode = (string) ($data['minimum_purchase_mode'] ?? 'none');
        $minCents = isset($data['min_purchase_cents']) ? (int) $data['min_purchase_cents'] : 0;
        $hasMinPurchase = $minMode === 'required' && $minCents > 0;

        return $hasValidity && $hasMinPurchase;
    }

    public function couponHasPlatformWideAssignmentRestrictions(\App\Models\Coupon $coupon): bool
    {
        $hasValidity = $coupon->valid_from !== null || $coupon->expires_at !== null;

        return $hasValidity && $coupon->hasMinimumPurchaseRequirement();
    }
}

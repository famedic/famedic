<?php

namespace App\Http\Requests\Admin;

use App\Models\PromoCode;
use App\Services\CouponAssignOtpService;
use App\Services\CouponEligibilityFormService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSharedPromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\PromoCode::class) ?? false;
    }

    public function rules(): array
    {
        $otpRequired = app(CouponAssignOtpService::class)->isRequired();

        return array_merge([
            'code' => ['nullable', 'string', 'max:64'],
            'auto_generate_code' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'max_redemptions' => ['required', 'integer', 'min:1'],
            'max_uses_per_user' => ['required', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'otp_verification_token' => [
                Rule::requiredIf($otpRequired),
                'nullable',
                'string',
                'uuid',
            ],
        ], app(CouponEligibilityFormService::class)->validationRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $autoGenerate = filter_var($this->input('auto_generate_code', false), FILTER_VALIDATE_BOOLEAN);
            $code = trim((string) $this->input('code', ''));

            if (! $autoGenerate && $code === '') {
                $validator->errors()->add('code', 'Ingresa un código o activa la generación automática.');
            }

            if (! $autoGenerate && $code !== '') {
                $normalized = PromoCode::normalizeCode($code);
                if (PromoCode::query()->where('code', $normalized)->exists()) {
                    $validator->errors()->add('code', 'Ya existe un código promocional con ese texto.');
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedPayload(): array
    {
        $validated = $this->validated();
        $validated['is_active'] = filter_var($validated['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $validated['auto_generate_code'] = filter_var($validated['auto_generate_code'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $validated['auto_generate_code']) {
            $validated['code'] = PromoCode::normalizeCode((string) ($validated['code'] ?? ''));
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function otpPayload(): array
    {
        $data = $this->validatedPayload();

        return array_merge($data, [
            'promo_creation' => true,
            'promo_type' => 'shared',
        ]);
    }
}

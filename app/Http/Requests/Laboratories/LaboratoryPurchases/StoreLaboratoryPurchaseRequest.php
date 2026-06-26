<?php

namespace App\Http\Requests\Laboratories\LaboratoryPurchases;

use App\Actions\Laboratories\ResolveLaboratoryCartTotalsAction;
use App\Exceptions\CouponApplicationException;
use App\Exceptions\PromoCodeException;
use App\Models\Coupon;
use App\Services\CouponApplicationService;
use App\Services\PromoCodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLaboratoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePaymentMethod();
    }

    public function rules(): array
    {
        return [
            'total' => 'required|numeric|min:0',
            'coupon_id' => ['nullable', 'integer', 'exists:coupons,id'],
            'promo_validation_token' => ['nullable', 'string', 'max:64'],
            'address' => [
                'required',
                'exists:addresses,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $address = auth()->user()->customer->addresses()->find($value);
                        if (! $address) {
                            $fail('La dirección seleccionada no es válida.');
                        }
                    }
                },
            ],
            'contact' => [
                'nullable',
                'exists:contacts,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $contact = auth()->user()->customer->contacts()->find($value);
                        if (! $contact) {
                            $fail('El contacto seleccionado no es válido.');
                        }
                    }
                },
            ],
            'laboratory_appointment' => [
                'nullable',
                'exists:laboratory_appointments,id,customer_id,'.auth()->user()->customer->id,
            ],
            'payment_method' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $brand = $this->route('laboratory_brand');
            $cartItems = auth()->user()->customer->laboratoryCartItems()
                ->ofBrand($brand)
                ->with('laboratoryTest')
                ->get();

            $totals = app(ResolveLaboratoryCartTotalsAction::class)(
                auth()->user()->customer,
                $brand,
                $cartItems,
            );
            $calculatedTotal = (int) $totals['total'];
            $laboratoryTotal = (int) $totals['laboratoryTotalCents'];

            if ((int) $this->input('total') !== $calculatedTotal) {
                $validator->errors()->add('total', 'El total de la compra no coincide con el carrito.');

                return;
            }

            $couponId = $this->filled('coupon_id') ? (int) $this->input('coupon_id') : null;
            $promoValidationToken = $this->filled('promo_validation_token')
                ? (string) $this->input('promo_validation_token')
                : null;

            if ($couponId !== null && $promoValidationToken !== null) {
                $validator->errors()->add(
                    'promo_validation_token',
                    'No puedes combinar un crédito asignado con un código promocional.'
                );

                return;
            }

            $amountToCharge = $calculatedTotal;
            $hasPromoOrCoupon = $couponId !== null || $promoValidationToken !== null;
            $cartHash = app(PromoCodeService::class)->buildLaboratoryCartHash($cartItems, $laboratoryTotal);

            if ($promoValidationToken !== null) {
                try {
                    $redemption = app(PromoCodeService::class)->resolveValidatedRedemption(
                        auth()->user(),
                        $promoValidationToken,
                        $laboratoryTotal,
                        $cartHash,
                    );
                    $amountToCharge = $calculatedTotal - (int) $redemption->discount_cents;
                } catch (PromoCodeException $e) {
                    $validator->errors()->add('promo_validation_token', $e->getMessage());

                    return;
                }
            } elseif ($couponId !== null) {
                try {
                    app(CouponApplicationService::class)->validateApplication(
                        auth()->user(),
                        $couponId,
                        $laboratoryTotal
                    );
                } catch (CouponApplicationException $e) {
                    $validator->errors()->add('coupon_id', $e->getMessage());

                    return;
                }

                $coupon = Coupon::query()->findOrFail($couponId);
                $amountToCharge = $calculatedTotal - app(CouponApplicationService::class)
                    ->resolveDiscountCents($coupon, $laboratoryTotal);
            }

            $allowed = $this->paymentMethodsForAmount($amountToCharge, $hasPromoOrCoupon);

            if (! in_array((string) $this->input('payment_method'), $allowed, true)) {
                $validator->errors()->add('payment_method', 'El método de pago seleccionado no es válido o ha expirado.');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function paymentMethodsForAmount(int $amountToChargeCents, bool $hasCoupon): array
    {
        $allowed = [];

        $customer = auth()->user()->customer;

        foreach ($customer->efevooTokens()->active()->excludeMockInProduction()->get() as $token) {
            $allowed[] = (string) $token->id;
        }

        if ($customer->has_odessa_afiliate_account) {
            $allowed[] = 'odessa';
        }

        if ($hasCoupon && $amountToChargeCents === 0) {
            $allowed[] = 'coupon_balance';
        }

        return $allowed;
    }

    private function normalizePaymentMethod(): void
    {
        if ($this->has('payment_method')) {
            $value = $this->input('payment_method');

            if (is_numeric($value)) {
                $this->merge([
                    'payment_method' => (string) $value,
                ]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'payment_method.required' => 'Debes seleccionar un método de pago.',
            'address.required' => 'Debes seleccionar una dirección de envío.',
            'address.exists' => 'La dirección seleccionada no existe.',
            'total.required' => 'El total es requerido.',
            'total.numeric' => 'El total debe ser un número válido.',
            'total.min' => 'El total debe ser mayor a 0.',
        ];
    }
}

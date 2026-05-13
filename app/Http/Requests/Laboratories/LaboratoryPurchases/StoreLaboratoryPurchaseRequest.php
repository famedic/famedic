<?php

namespace App\Http\Requests\Laboratories\LaboratoryPurchases;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Services\CouponApplicationService;
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

            $totals = app(CalculateTotalsAndDiscountAction::class)($cartItems);
            $calculatedTotal = (int) $totals['total'];

            if ((int) $this->input('total') !== $calculatedTotal) {
                $validator->errors()->add('total', 'El total de la compra no coincide con el carrito.');

                return;
            }

            $couponId = $this->filled('coupon_id') ? (int) $this->input('coupon_id') : null;

            $amountToCharge = $calculatedTotal;
            if ($couponId !== null) {
                try {
                    app(CouponApplicationService::class)->validateApplication(
                        auth()->user(),
                        $couponId,
                        $calculatedTotal
                    );
                } catch (CouponApplicationException $e) {
                    $validator->errors()->add('coupon_id', $e->getMessage());

                    return;
                }

                $coupon = Coupon::query()->findOrFail($couponId);
                $amountToCharge = $calculatedTotal - $coupon->remaining_cents;
            }

            $allowed = $this->paymentMethodsForAmount($amountToCharge, $couponId !== null);

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

        foreach ($customer->efevooTokens()->active()->get() as $token) {
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

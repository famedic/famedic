<?php

namespace App\Support\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\User;
use App\Services\CouponApplicationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class CartCouponSupport
{
    public function __construct(
        private readonly CouponApplicationService $couponApplicationService,
    ) {}

    public function draftForBrand(Customer $customer, LaboratoryBrand $brand): ?LaboratoryCheckoutDraft
    {
        return LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->with('coupon')
            ->first();
    }

    public function findCouponByCodeForUser(User $user, string $code): ?Coupon
    {
        $normalizedCode = Str::upper(trim($code));

        if ($normalizedCode === '') {
            return null;
        }

        return Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->whereHas('couponUsers', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNull('used_at'))
            ->first();
    }

    /**
     * @return array{coupon: Coupon}|array{error: string}
     */
    public function validateCouponForCart(User $user, Coupon $coupon, int $cartTotalCents): array
    {
        try {
            $this->couponApplicationService->validateApplication($user, $coupon->id, $cartTotalCents);

            return ['coupon' => $coupon->fresh()];
        } catch (ModelNotFoundException) {
            return ['error' => 'COUPON_NOT_FOUND'];
        } catch (CouponApplicationException $exception) {
            return ['error' => $this->mapApplicationError($exception)];
        }
    }

    public function couponDiscountCents(?Coupon $coupon, int $cartTotalCents): int
    {
        if (! $coupon || $coupon->remaining_cents <= 0) {
            return 0;
        }

        return min($coupon->remaining_cents, $cartTotalCents);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formatCouponPayload(?Coupon $coupon, int $cartTotalCents): ?array
    {
        if (! $coupon) {
            return null;
        }

        $discountCents = $this->couponDiscountCents($coupon, $cartTotalCents);

        return [
            'code' => $coupon->code,
            'type' => 'balance',
            'value' => $coupon->remaining_cents,
            'discount_cents' => $discountCents,
            'description' => $coupon->description ?: 'Saldo a favor',
        ];
    }

    /**
     * @param  Collection<int, \App\Models\LaboratoryCartItem>  $items
     * @return array{
     *     brand: string,
     *     currency: string,
     *     items_count: int,
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     coupon_discount_cents: int,
     *     total_cents: int,
     *     coupon: array<string, mixed>|null,
     * }
     */
    public function buildTotalsWithCoupon(
        LaboratoryBrand $brand,
        Collection $items,
        ?Coupon $coupon,
    ): array {
        $subtotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->public_price_cents);
        $famedicTotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);
        $catalogDiscountCents = $subtotalCents - $famedicTotalCents;
        $couponDiscountCents = $this->couponDiscountCents($coupon, $famedicTotalCents);

        return [
            'brand' => $brand->value,
            'currency' => 'MXN',
            'items_count' => $items->count(),
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $catalogDiscountCents,
            'coupon_discount_cents' => $couponDiscountCents,
            'total_cents' => max(0, $famedicTotalCents - $couponDiscountCents),
            'coupon' => $this->formatCouponPayload($coupon, $famedicTotalCents),
        ];
    }

    /**
     * @param  Collection<int, \App\Models\LaboratoryCartItem>  $items
     * @return array{
     *     currency: string,
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     coupon_discount_cents: int,
     *     total_cents: int,
     *     coupon: array<string, mixed>|null,
     * }
     */
    public function totalsFromItems(Collection $items, ?Coupon $coupon = null): array
    {
        $subtotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->public_price_cents);
        $famedicTotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);
        $catalogDiscountCents = $subtotalCents - $famedicTotalCents;
        $couponDiscountCents = $this->couponDiscountCents($coupon, $famedicTotalCents);

        return [
            'currency' => 'MXN',
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $catalogDiscountCents,
            'coupon_discount_cents' => $couponDiscountCents,
            'total_cents' => max(0, $famedicTotalCents - $couponDiscountCents),
            'coupon' => $this->formatCouponPayload($coupon, $famedicTotalCents),
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function couponWarnings(User $user, ?Coupon $coupon, int $cartTotalCents): array
    {
        if (! $coupon) {
            return [];
        }

        $validation = $this->validateCouponForCart($user, $coupon, $cartTotalCents);

        if (isset($validation['error'])) {
            return [[
                'code' => $validation['error'],
                'message' => $this->errorMessage($validation['error']),
            ]];
        }

        return [];
    }

    public function persistCoupon(
        Customer $customer,
        LaboratoryBrand $brand,
        ?int $couponId,
    ): LaboratoryCheckoutDraft {
        return LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            [
                'coupon_id' => $couponId,
            ],
        );
    }

    private function mapApplicationError(CouponApplicationException $exception): string
    {
        $message = $exception->getMessage();

        if ($message === 'Este cupón ya fue utilizado.') {
            return 'COUPON_EXPIRED';
        }

        if ($message === 'El cupón no está activo.' || $message === 'El cupón no está autorizado.') {
            return 'COUPON_NOT_APPLICABLE';
        }

        if ($message === 'El cupón no tiene saldo disponible.') {
            return 'COUPON_EXPIRED';
        }

        return 'COUPON_NOT_APPLICABLE';
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'COUPON_EXPIRED' => 'El cupón ya no está disponible.',
            'COUPON_NOT_APPLICABLE' => 'El cupón no puede aplicarse a este carrito.',
            'COUPON_NOT_FOUND' => 'El cupón no fue encontrado.',
            default => 'El cupón no puede aplicarse a este carrito.',
        };
    }
}

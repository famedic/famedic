<?php

namespace App\Services\Api\V1;

use App\Enums\CouponType;
use App\Exceptions\CouponApplicationException;
use App\Exceptions\MedicalServiceBenefitException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartBenefit;
use App\Services\CouponApplicationService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MedicalServiceCartBenefitService
{
    public function __construct(
        private readonly MedicalServiceCartService $cartService,
        private readonly CouponApplicationService $couponApplicationService,
    ) {}

    public function apply(Customer $customer, User $user, string $rawCode): MedicalServiceCart
    {
        return DB::transaction(function () use ($customer, $user, $rawCode) {
            $cart = $this->lockedCart($customer);
            $coupon = $this->findCouponByCodeForUser($user, $rawCode);
            if (! $coupon) {
                throw new MedicalServiceBenefitException('COUPON_NOT_FOUND', 'El cupón no fue encontrado.');
            }

            $subtotalCents = $this->subtotalCents($cart);
            if ($subtotalCents <= 0) {
                throw new MedicalServiceBenefitException('EMPTY_CART', 'No se puede aplicar un beneficio con un carrito vacío.');
            }

            try {
                $coupon = $this->couponApplicationService->validateMedicalServicePreview(
                    $user,
                    (int) $coupon->id,
                    $subtotalCents,
                );
            } catch (ModelNotFoundException) {
                throw new MedicalServiceBenefitException('COUPON_NOT_FOUND', 'El cupón no fue encontrado.');
            } catch (CouponApplicationException $exception) {
                throw new MedicalServiceBenefitException(
                    $this->mapApplicationError($exception),
                    'El cupón no puede aplicarse a este carrito.',
                );
            }

            $benefitType = $coupon->type === CouponType::Balance
                ? MedicalServiceCartBenefit::TYPE_CREDIT
                : MedicalServiceCartBenefit::TYPE_COUPON;

            $existing = $cart->benefits()->first();
            if ($existing !== null && ((int) $existing->coupon_id !== (int) $coupon->id)) {
                throw new MedicalServiceBenefitException(
                    'BENEFIT_ALREADY_APPLIED',
                    'Ya existe un beneficio aplicado al carrito médico.',
                );
            }

            $benefit = $cart->benefits()->updateOrCreate(
                [],
                [
                    'coupon_id' => $coupon->id,
                    'benefit_type' => $benefitType,
                    'applied_cents' => $this->amountFor($coupon, $subtotalCents),
                ],
            );

            $this->cartService->recalculate($cart);

            return $cart->fresh(['items', 'benefits.coupon']);
        });
    }

    public function remove(Customer $customer): array
    {
        return DB::transaction(function () use ($customer) {
            $cart = $this->lockedCart($customer);
            $benefit = $cart->benefits()->with('coupon')->first();

            if (! $benefit) {
                $this->cartService->recalculate($cart);

                return [
                    'removed' => false,
                    'cart' => $cart->fresh(['items', 'benefits.coupon']),
                    'removed_amount_cents' => 0,
                    'removed_benefit_type' => null,
                    'removed_coupon_id' => null,
                ];
            }

            $removedAmount = (int) $benefit->applied_cents;
            $removedBenefitType = $benefit->benefit_type;
            $removedCouponId = (int) $benefit->coupon_id;
            $benefit->delete();
            $this->cartService->recalculate($cart);

            return [
                'removed' => true,
                'cart' => $cart->fresh(['items', 'benefits.coupon']),
                'removed_amount_cents' => $removedAmount,
                'removed_benefit_type' => $removedBenefitType,
                'removed_coupon_id' => $removedCouponId,
            ];
        });
    }

    public function recalculate(MedicalServiceCart $cart): MedicalServiceCart
    {
        $this->cartService->recalculate($cart);

        return $cart->fresh(['items', 'benefits.coupon']);
    }

    private function lockedCart(Customer $customer): MedicalServiceCart
    {
        $cart = MedicalServiceCart::query()
            ->where('customer_id', $customer->id)
            ->where('service_line', MedicalServiceCart::SERVICE_LINE)
            ->where('status', MedicalServiceCart::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        return $cart ?? $this->cartService->activeCart($customer);
    }

    private function subtotalCents(MedicalServiceCart $cart): int
    {
        return max(0, (int) $cart->items()->sum('subtotal_cents'));
    }

    private function findCouponByCodeForUser(User $user, string $rawCode): ?Coupon
    {
        $code = Str::upper(trim($rawCode));
        if ($code === '') {
            return null;
        }

        return Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->whereHas('couponUsers', fn ($query) => $query->where('user_id', $user->id))
            ->first();
    }

    private function amountFor(Coupon $coupon, int $subtotalCents): int
    {
        return min(max(0, (int) $coupon->remaining_cents), max(0, $subtotalCents));
    }

    private function mapApplicationError(CouponApplicationException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'venció') || str_contains($message, 'venc')) {
            return 'COUPON_EXPIRED';
        }

        if (str_contains($message, 'disponible a partir')) {
            return 'COUPON_NOT_APPLICABLE';
        }

        return 'COUPON_NOT_APPLICABLE';
    }
}

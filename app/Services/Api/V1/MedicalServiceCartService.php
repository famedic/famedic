<?php

namespace App\Services\Api\V1;

use App\Exceptions\MedicalServiceCartConflictException;
use App\Exceptions\MedicalServiceMembershipActiveException;
use App\Models\Customer;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Services\CouponApplicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MedicalServiceCartService
{
    public function __construct(
        private readonly MembershipPlanCatalogService $planCatalog,
        private readonly CouponApplicationService $couponApplicationService,
    ) {}

    public function activeCart(Customer $customer): MedicalServiceCart
    {
        $cart = MedicalServiceCart::query()->firstOrCreate(
            [
                'customer_id' => $customer->id,
                'service_line' => MedicalServiceCart::SERVICE_LINE,
                'status' => MedicalServiceCart::STATUS_ACTIVE,
            ],
            [
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'credit_applied_cents' => 0,
                'total_cents' => 0,
            ],
        );

        $this->recalculate($cart);

        return $cart->fresh(['items', 'benefits.coupon']);
    }

    public function addBasicPlan(Customer $customer, int $quantity = 1): MedicalServiceCart
    {
        if ($quantity !== 1) {
            throw new InvalidArgumentException('La cantidad de una membresía debe ser 1.');
        }

        return DB::transaction(function () use ($customer, $quantity): MedicalServiceCart {
            $cart = MedicalServiceCart::query()
                ->where('customer_id', $customer->id)
                ->where('service_line', MedicalServiceCart::SERVICE_LINE)
                ->where('status', MedicalServiceCart::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $cart) {
                $cart = $this->activeCart($customer);
            }

            if ($cart->items()->where('membership_plan_code', MedicalServiceCartItem::BASIC_ANNUAL_PLAN)->exists()) {
                throw new MedicalServiceCartConflictException(
                    'MEMBERSHIP_ALREADY_IN_CART',
                    'El Plan Básico ya existe en el carrito médico.',
                );
            }

            $membershipStatus = $this->planCatalog->status($customer);
            if ($membershipStatus['status'] === 'active') {
                throw new MedicalServiceMembershipActiveException(
                    $membershipStatus['source'] === 'sponsored'
                        ? 'sponsored_membership_active'
                        : 'membership_already_active',
                );
            }

            $plan = collect($this->planCatalog->plans())
                ->firstWhere('code', MedicalServiceCartItem::BASIC_ANNUAL_PLAN);

            if (! $plan) {
                throw (new ModelNotFoundException)->setModel('membership plan', [MedicalServiceCartItem::BASIC_ANNUAL_PLAN]);
            }

            $unitPriceCents = (int) $plan['price_cents'];
            $subtotalCents = $unitPriceCents * $quantity;

            $cart->items()->create([
                'item_type' => MedicalServiceCartItem::ITEM_TYPE_MEMBERSHIP,
                'membership_plan_code' => MedicalServiceCartItem::BASIC_ANNUAL_PLAN,
                'name' => $plan['name'],
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'subtotal_cents' => $subtotalCents,
                'requires_appointment' => false,
            ]);

            $this->recalculate($cart);

            return $cart->fresh('items');
        });
    }

    public function removeItem(Customer $customer, int $itemId): MedicalServiceCart
    {
        $item = MedicalServiceCartItem::query()->with('cart')->find($itemId);

        if (! $item) {
            throw new MedicalServiceCartConflictException(
                'CART_ITEM_NOT_FOUND',
                'Ítem de carrito no encontrado.',
            );
        }

        if ($item->cart->customer_id !== $customer->id) {
            throw new MedicalServiceCartConflictException(
                'FORBIDDEN',
                'No tienes permiso para eliminar este ítem.',
            );
        }

        if ($item->cart->service_line !== MedicalServiceCart::SERVICE_LINE) {
            throw new MedicalServiceCartConflictException(
                'CART_SERVICE_LINE_MISMATCH',
                'El ítem no pertenece al carrito de Servicio médico.',
            );
        }

        $cart = $item->cart;
        $item->delete();
        $this->recalculate($cart);

        return $cart->fresh('items');
    }

    public function recalculate(MedicalServiceCart $cart): void
    {
        $subtotalCents = (int) $cart->items()->sum('subtotal_cents');
        $discountCents = 0;
        $creditAppliedCents = 0;

        $benefit = $cart->benefits()->with('coupon')->first();
        if ($benefit?->coupon !== null && $cart->customer?->user !== null && $subtotalCents > 0) {
            try {
                $this->couponApplicationService->validateMedicalServicePreview(
                    $cart->customer->user,
                    (int) $benefit->coupon_id,
                    $subtotalCents,
                );

                $amount = min((int) $benefit->coupon->remaining_cents, $subtotalCents);
                if ($benefit->benefit_type === 'credit') {
                    $creditAppliedCents = max(0, $amount);
                } else {
                    $discountCents = max(0, $amount);
                }

                if ((int) $benefit->applied_cents !== $amount) {
                    $benefit->update(['applied_cents' => max(0, $amount)]);
                }
            } catch (\Throwable) {
                if ((int) $benefit->applied_cents !== 0) {
                    $benefit->update(['applied_cents' => 0]);
                }
            }
        } elseif ($benefit !== null && (int) $benefit->applied_cents !== 0) {
            $benefit->update(['applied_cents' => 0]);
        }

        $cart->update([
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $discountCents,
            'credit_applied_cents' => $creditAppliedCents,
            'total_cents' => max(0, $subtotalCents - $discountCents - $creditAppliedCents),
        ]);
    }
}

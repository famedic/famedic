<?php

namespace App\Services\Laboratory;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryStore;
use App\Services\LaboratoryRequirements\BranchResolver;
use App\Services\LaboratoryRequirements\CartRequirementAggregator;
use App\Services\PromoCodeService;
use App\Support\LaboratoryRequirements\BranchMatchResult;
use Illuminate\Support\Collection;

class SelectedLaboratoryStoreDraftService
{
    public function __construct(
        private readonly CartRequirementAggregator $aggregator,
        private readonly BranchResolver $branchResolver,
        private readonly CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        private readonly PromoCodeService $promoCodeService,
    ) {}

    /**
     * @return array{draft: LaboratoryCheckoutDraft, store: LaboratoryStore, cart_hash: string}
     */
    public function store(Customer $customer, LaboratoryBrand $brand, LaboratoryStore $store): array
    {
        $validation = $this->validateSelection($customer, $brand, $store);

        $draft = LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $brand,
            ],
            [
                'selected_laboratory_store_id' => $store->id,
                'selected_laboratory_store_validated_at' => now(),
                'selected_laboratory_store_cart_hash' => $validation['cart_hash'],
            ],
        );

        return [
            'draft' => $draft->fresh('laboratoryStore'),
            'store' => $store,
            'cart_hash' => $validation['cart_hash'],
        ];
    }

    public function clear(Customer $customer, LaboratoryBrand $brand): void
    {
        LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->update([
                'selected_laboratory_store_id' => null,
                'selected_laboratory_store_validated_at' => null,
                'selected_laboratory_store_cart_hash' => null,
            ]);
    }

    /**
     * @return array{selected: bool, store: LaboratoryStore|null, validation: array<string, string>|null}
     */
    public function current(Customer $customer, LaboratoryBrand $brand): array
    {
        $state = $this->checkoutState($customer, $brand);

        if ($state['selected'] || ($state['validation']['status'] ?? null) === 'stale') {
            return $state;
        }

        return [
            'selected' => false,
            'store' => null,
            'validation' => null,
        ];
    }

    /**
     * @return array{selected: bool, store: LaboratoryStore|null, validation: array<string, string>|null}
     */
    public function conciergeRecommendation(Customer $customer, LaboratoryBrand $brand): array
    {
        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        if (! $draft?->selected_laboratory_store_id) {
            return [
                'selected' => false,
                'store' => null,
                'validation' => null,
            ];
        }

        $store = LaboratoryStore::withTrashed()->find($draft->selected_laboratory_store_id);
        if (! $store) {
            return [
                'selected' => true,
                'store' => null,
                'validation' => [
                    'status' => 'invalid',
                    'reason' => 'branch_missing',
                ],
            ];
        }

        $items = $this->brandCartItems($customer, $brand);
        if ($items->isEmpty()) {
            return [
                'selected' => true,
                'store' => $store,
                'validation' => [
                    'status' => 'unknown',
                    'reason' => 'empty_cart',
                ],
            ];
        }

        $currentHash = $this->cartHash($items);
        if ($draft->selected_laboratory_store_cart_hash !== $currentHash) {
            return [
                'selected' => true,
                'store' => $store,
                'validation' => [
                    'status' => 'stale',
                    'reason' => 'cart_hash_changed',
                ],
            ];
        }

        try {
            $this->validateSelection($customer, $brand, $store);
        } catch (SelectedLaboratoryStoreException $exception) {
            return [
                'selected' => true,
                'store' => $store,
                'validation' => [
                    'status' => in_array($exception->reason, ['cart_not_resolvable'], true) ? 'unknown' : 'invalid',
                    'reason' => $exception->reason,
                ],
            ];
        }

        return [
            'selected' => true,
            'store' => $store,
            'validation' => ['status' => 'valid'],
        ];
    }

    /**
     * @return array{selected: bool, store: LaboratoryStore|null, validation: array<string, string>|null}
     */
    public function checkoutState(Customer $customer, LaboratoryBrand $brand): array
    {
        $draft = LaboratoryCheckoutDraft::query()
            ->with('laboratoryStore')
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        if (! $draft?->selected_laboratory_store_id || ! $draft->laboratoryStore) {
            return [
                'selected' => false,
                'store' => null,
                'validation' => null,
            ];
        }

        $items = $this->brandCartItems($customer, $brand);
        if ($items->isEmpty()) {
            return $this->invalidSelection('empty_cart');
        }

        $currentHash = $this->cartHash($items);
        if ($draft->selected_laboratory_store_cart_hash !== $currentHash) {
            return $this->staleSelection('cart_hash_changed');
        }

        try {
            $this->validateSelection($customer, $brand, $draft->laboratoryStore);
        } catch (SelectedLaboratoryStoreException $exception) {
            return $this->invalidSelection($exception->reason);
        }

        return [
            'selected' => true,
            'store' => $draft->laboratoryStore,
            'validation' => ['status' => 'valid'],
        ];
    }

    /**
     * @return array{selected: true, store: LaboratoryStore, validation: array<string, string>}
     *
     * @throws SelectedLaboratoryStoreException
     */
    public function assertValidForCheckout(Customer $customer, LaboratoryBrand $brand): array
    {
        $state = $this->checkoutState($customer, $brand);

        if (
            $state['selected'] === true
            && $state['store'] instanceof LaboratoryStore
            && ($state['validation']['status'] ?? null) === 'valid'
        ) {
            return $state;
        }

        $reason = $state['validation']['reason'] ?? 'selected_store_missing';

        throw new SelectedLaboratoryStoreException(
            $this->checkoutBlockingMessage($reason),
            $reason,
        );
    }

    /**
     * @return array{cart_hash: string, branch: BranchMatchResult}
     *
     * @throws SelectedLaboratoryStoreException
     */
    private function validateSelection(Customer $customer, LaboratoryBrand $brand, LaboratoryStore $store): array
    {
        if ($store->brand !== $brand) {
            throw new SelectedLaboratoryStoreException(
                'La sucursal seleccionada no pertenece a este laboratorio.',
                'branch_brand_mismatch',
            );
        }

        if (! $store->is_active || $store->trashed()) {
            throw new SelectedLaboratoryStoreException(
                'La sucursal seleccionada no está activa.',
                'branch_inactive',
            );
        }

        $items = $this->brandCartItems($customer, $brand);
        if ($items->isEmpty()) {
            throw new SelectedLaboratoryStoreException(
                'No hay estudios en este carrito para seleccionar una sucursal.',
                'empty_cart',
            );
        }

        $requirements = $this->aggregator->aggregateItems($items, (string) $customer->id);
        if (! $requirements->isResolvable) {
            throw new SelectedLaboratoryStoreException(
                'La sucursal seleccionada ya no es compatible con los estudios actuales.',
                'cart_not_resolvable',
            );
        }

        $resolution = $this->branchResolver->resolve($requirements);
        $branch = collect($resolution->brands[$brand->value] ?? $resolution->branches)
            ->first(fn (BranchMatchResult $branch) => (int) $branch->branch->id === (int) $store->id);

        if (! $branch?->isCompatible) {
            throw new SelectedLaboratoryStoreException(
                'La sucursal seleccionada ya no es compatible con los estudios actuales.',
                'branch_not_compatible',
            );
        }

        return [
            'cart_hash' => $this->cartHash($items),
            'branch' => $branch,
        ];
    }

    private function brandCartItems(Customer $customer, LaboratoryBrand $brand): Collection
    {
        return $customer
            ->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest.laboratoryTestCategory')
            ->get();
    }

    private function cartHash(Collection $items): string
    {
        $totals = ($this->calculateTotalsAndDiscountAction)($items);

        return $this->promoCodeService->buildLaboratoryCartHash($items, (int) $totals['total']);
    }

    /**
     * @return array{selected: false, store: null, validation: array{status: string}}
     */
    private function staleSelection(?string $reason = null): array
    {
        return [
            'selected' => false,
            'store' => null,
            'validation' => array_filter([
                'status' => 'stale',
                'reason' => $reason,
            ]),
        ];
    }

    /**
     * @return array{selected: false, store: null, validation: array{status: string, reason: string}}
     */
    private function invalidSelection(string $reason): array
    {
        return [
            'selected' => false,
            'store' => null,
            'validation' => [
                'status' => 'invalid',
                'reason' => $reason,
            ],
        ];
    }

    private function checkoutBlockingMessage(string $reason): string
    {
        return match ($reason) {
            'cart_hash_changed' => 'Los estudios de tu carrito cambiaron. Puedes actualizar la recomendación de sucursal.',
            'branch_not_compatible', 'cart_not_resolvable' => 'La sucursal seleccionada ya no coincide con los requisitos actuales de tus estudios.',
            'branch_inactive' => 'La sucursal seleccionada ya no está activa.',
            'branch_brand_mismatch' => 'La sucursal seleccionada no pertenece a este laboratorio.',
            'empty_cart' => 'No hay estudios en este carrito.',
            default => 'Puedes elegir una sucursal recomendada si lo deseas.',
        };
    }
}

<?php

namespace App\Actions\Laboratories;

use App\DTOs\Laboratories\LaboratoryCheckoutResumeResolution;
use App\Enums\MonitoringCartStatus;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryCheckoutResumeLink;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ResolveLaboratoryCheckoutResumeLinkAction
{
    public function __invoke(string $plainToken, ?User $user): LaboratoryCheckoutResumeResolution
    {
        $link = LaboratoryCheckoutResumeLink::query()
            ->with(['cart.user.customer', 'customer'])
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $link) {
            return new LaboratoryCheckoutResumeResolution(LaboratoryCheckoutResumeResolution::INVALID);
        }

        if ($link->isRevoked()) {
            return new LaboratoryCheckoutResumeResolution(LaboratoryCheckoutResumeResolution::REVOKED, link: $link);
        }

        if ($link->isExpired()) {
            return new LaboratoryCheckoutResumeResolution(LaboratoryCheckoutResumeResolution::EXPIRED, link: $link);
        }

        if (! $user) {
            return new LaboratoryCheckoutResumeResolution(LaboratoryCheckoutResumeResolution::UNAUTHENTICATED, link: $link);
        }

        $customer = $user->customer;
        if (! $customer || (int) $customer->id !== (int) $link->customer_id) {
            return new LaboratoryCheckoutResumeResolution(LaboratoryCheckoutResumeResolution::FORBIDDEN, link: $link);
        }

        $cart = $link->cart;
        if (! $cart || (int) $cart->user_id !== (int) $user->id) {
            return new LaboratoryCheckoutResumeResolution(LaboratoryCheckoutResumeResolution::MISSING_CART, link: $link);
        }

        $purchase = $cart->explicitLaboratoryPurchase();
        if ($purchase) {
            $link->forceFill(['last_used_at' => now()])->save();
            $this->logResolution($link, $cart->id, 'explicit_purchase', $purchase->id);

            return new LaboratoryCheckoutResumeResolution(
                LaboratoryCheckoutResumeResolution::COMPLETED,
                route('laboratory-purchases.show', ['laboratory_purchase' => $purchase->id]),
                $link,
            );
        }

        if ($cart->status === MonitoringCartStatus::Completed) {
            $link->forceFill(['last_used_at' => now()])->save();
            $this->logResolution($link, $cart->id, 'completed_purchase_list');

            return new LaboratoryCheckoutResumeResolution(
                LaboratoryCheckoutResumeResolution::COMPLETED,
                route('user.purchases.index'),
                $link,
            );
        }

        $brand = $link->laboratory_brand;
        if (! $customer->laboratoryCartItems()->ofBrand($brand)->exists()) {
            $this->logResolution($link, $cart->id, 'empty_cart');

            return new LaboratoryCheckoutResumeResolution(
                LaboratoryCheckoutResumeResolution::EMPTY_CART,
                route('laboratory.shopping-cart', ['laboratory_brand' => $brand]),
                $link,
            );
        }

        $draft = LaboratoryCheckoutDraft::query()
            ->where('customer_id', $customer->id)
            ->where('laboratory_brand', $brand)
            ->first();

        $this->removeForeignDraftReferences($draft, $customer->id);

        $query = array_filter([
            'step' => $draft?->checkout_step,
        ], fn ($value) => filled($value));

        $link->forceFill(['last_used_at' => now()])->save();
        $this->logResolution($link, $cart->id, 'checkout');

        return new LaboratoryCheckoutResumeResolution(
            LaboratoryCheckoutResumeResolution::READY,
            route('laboratory.checkout', [
                'laboratory_brand' => $brand,
                ...$query,
            ]),
            $link,
        );
    }

    private function logResolution(
        LaboratoryCheckoutResumeLink $link,
        int $cartId,
        string $destination,
        ?int $purchaseId = null,
    ): void {
        Log::info('Laboratory checkout resume link resolved', array_filter([
            'resume_link_id' => $link->id,
            'cart_id' => $cartId,
            'customer_id' => $link->customer_id,
            'destination' => $destination,
            'purchase_id' => $purchaseId,
        ], static fn ($value) => $value !== null));
    }

    private function removeForeignDraftReferences(?LaboratoryCheckoutDraft $draft, int $customerId): void
    {
        if (! $draft) {
            return;
        }

        $updates = [];

        if ($draft->contact_id && ! Contact::query()->whereKey($draft->contact_id)->where('customer_id', $customerId)->exists()) {
            $updates['contact_id'] = null;
        }

        if ($draft->address_id && ! Address::query()->whereKey($draft->address_id)->where('customer_id', $customerId)->exists()) {
            $updates['address_id'] = null;
        }

        if ($updates !== []) {
            $draft->forceFill($updates)->save();
        }
    }
}

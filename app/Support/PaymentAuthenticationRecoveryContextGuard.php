<?php

namespace App\Support;

use App\Enums\MonitoringCartStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Services\Monitoring\SyncMonitoringCartService;

class PaymentAuthenticationRecoveryContextGuard
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextDataNormalizer $normalizer
    ) {}

    public function ownedBy(Customer $customer, PaymentAuthenticationRecoveryContext $context): bool
    {
        return (int) $context->customer_id === (int) $customer->id;
    }

    public function requireOwned(Customer $customer, PaymentAuthenticationRecoveryContext $context): PaymentAuthenticationRecoveryContext
    {
        if (! $this->ownedBy($customer, $context)) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $context;
    }

    public function requireUsable(Customer $customer, PaymentAuthenticationRecoveryContext $context): PaymentAuthenticationRecoveryContext
    {
        $this->requireOwned($customer, $context);

        if ($context->isExpired() || $context->status === PaymentAuthenticationRecoveryContextStatus::Expired) {
            throw PaymentAuthenticationRecoveryContextException::expired();
        }

        if (in_array($context->status, [
            PaymentAuthenticationRecoveryContextStatus::Cancelled,
            PaymentAuthenticationRecoveryContextStatus::Recovered,
        ], true)) {
            throw PaymentAuthenticationRecoveryContextException::invalidStatus();
        }

        $this->assertResources($customer, $context);

        return $context;
    }

    public function requireAttachable(Customer $customer, PaymentAuthenticationRecoveryContext $context): PaymentAuthenticationRecoveryContext
    {
        $this->requireUsable($customer, $context);

        if (! $context->canAttachAttempt() && $context->status !== PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress) {
            throw PaymentAuthenticationRecoveryContextException::invalidStatus();
        }

        return $context;
    }

    public function isUsableForReturn(Customer $customer, PaymentAuthenticationRecoveryContext $context): bool
    {
        try {
            $this->requireOwned($customer, $context);

            if ($context->isExpired()) {
                return false;
            }

            if (in_array($context->status, [
                PaymentAuthenticationRecoveryContextStatus::Cancelled,
                PaymentAuthenticationRecoveryContextStatus::Expired,
                PaymentAuthenticationRecoveryContextStatus::Recovered,
            ], true)) {
                return false;
            }

            $this->assertResources($customer, $context);

            return true;
        } catch (PaymentAuthenticationRecoveryContextException) {
            return false;
        }
    }

    public function assertResources(Customer $customer, PaymentAuthenticationRecoveryContext $context): void
    {
        $type = $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);

        if (! $type) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        $this->normalizer->assertPersistedOwnership(
            $customer,
            $type,
            $context->allowlistedContextData(),
            $context->cart_id ? (int) $context->cart_id : null
        );

        if ($context->cart_id) {
            $cart = $context->cart ?? $context->cart()->first();

            if (! $cart || $cart->status === MonitoringCartStatus::Completed) {
                throw PaymentAuthenticationRecoveryContextException::invalidStatus();
            }
        }

        if ($type === PaymentAuthenticationRecoveryContextType::LaboratoryCheckout) {
            $brand = \App\Enums\LaboratoryBrand::tryFrom((string) $context->contextDataValue('laboratory_brand'));

            if ($brand && ! $customer->laboratoryCartItems()->ofBrand($brand)->exists()) {
                throw PaymentAuthenticationRecoveryContextException::invalidStatus();
            }
        }

        if ($type === PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout) {
            if (! $customer->onlinePharmacyCartItems()->exists()) {
                $activeCart = app(SyncMonitoringCartService::class)->activeCartForCustomer(
                    $customer,
                    \App\Enums\MonitoringCartType::Pharmacy
                );

                if (! $activeCart) {
                    throw PaymentAuthenticationRecoveryContextException::invalidStatus();
                }
            }
        }
    }

    public function hasSavedCart(Customer $customer, PaymentAuthenticationRecoveryContext $context): bool
    {
        $type = $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);

        if ($context->cart_id) {
            $cart = $context->cart ?? $context->cart()->first();

            if ($cart && $cart->status !== MonitoringCartStatus::Completed && (int) $cart->user_id === (int) $customer->user_id) {
                return true;
            }
        }

        if ($type === PaymentAuthenticationRecoveryContextType::LaboratoryCheckout) {
            $brand = \App\Enums\LaboratoryBrand::tryFrom((string) $context->contextDataValue('laboratory_brand'));

            return $brand
                ? $customer->laboratoryCartItems()->ofBrand($brand)->exists()
                : $customer->laboratoryCartItems()->exists();
        }

        if ($type === PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout) {
            return $customer->onlinePharmacyCartItems()->exists();
        }

        return false;
    }
}

<?php

namespace App\Enums;

enum PaymentAuthenticationRecoveryContextType: string
{
    case PaymentMethodSettings = 'payment_method_settings';
    case LaboratoryCheckout = 'laboratory_checkout';
    case MedicalAttentionCheckout = 'medical_attention_checkout';
    case MedicalAttentionModal = 'medical_attention_modal';
    case OnlinePharmacyCheckout = 'online_pharmacy_checkout';

    /**
     * Display value for attempts without a recovery context.
     */
    public const UNKNOWN = 'unknown';

    /**
     * Closed origin query values mapped by the backend. Never accept context_type from the frontend.
     */
    public static function fromOrigin(?string $origin): ?self
    {
        return match ($origin) {
            'settings' => self::PaymentMethodSettings,
            'laboratory' => self::LaboratoryCheckout,
            'medical-attention-checkout' => self::MedicalAttentionCheckout,
            'medical-attention-modal' => self::MedicalAttentionModal,
            'online-pharmacy' => self::OnlinePharmacyCheckout,
            default => null,
        };
    }

    public static function fromReturnRouteName(?string $routeName): ?self
    {
        return match ($routeName) {
            'payment-methods.index' => self::PaymentMethodSettings,
            'laboratory.checkout' => self::LaboratoryCheckout,
            'medical-attention.checkout' => self::MedicalAttentionCheckout,
            'medical-attention' => self::MedicalAttentionModal,
            'online-pharmacy.checkout' => self::OnlinePharmacyCheckout,
            default => null,
        };
    }

    public function returnRouteName(): string
    {
        return match ($this) {
            self::PaymentMethodSettings => 'payment-methods.index',
            self::LaboratoryCheckout => 'laboratory.checkout',
            self::MedicalAttentionCheckout => 'medical-attention.checkout',
            self::MedicalAttentionModal => 'medical-attention',
            self::OnlinePharmacyCheckout => 'online-pharmacy.checkout',
        };
    }

    public function fallbackRouteName(): string
    {
        return match ($this) {
            self::PaymentMethodSettings => 'payment-methods.index',
            self::LaboratoryCheckout => 'laboratory-brand-selection',
            self::MedicalAttentionCheckout, self::MedicalAttentionModal => 'medical-attention',
            self::OnlinePharmacyCheckout => 'online-pharmacy',
        };
    }

    public function supportsPayPal(): bool
    {
        return match ($this) {
            self::LaboratoryCheckout, self::MedicalAttentionCheckout => true,
            // The current medical-attention modal has no PayPal capture path to resume.
            self::MedicalAttentionModal, self::PaymentMethodSettings, self::OnlinePharmacyCheckout => false,
        };
    }

    public function supportsAnotherCard(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}

<?php

namespace App\Services\Laboratory;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;

/**
 * Fuente de verdad de transiciones permitidas en el checkout web de laboratorio.
 */
class LaboratoryCheckoutStepGuard
{
    public const STEP_PATIENT = 'patient';

    public const STEP_ADDRESS = 'address';

    public const STEP_APPOINTMENT = 'appointment';

    public const STEP_PAYMENT = 'payment';

    public const STEP_CONFIRMATION = 'confirmation';

    public function __construct(
        private LaboratoryCheckoutFlowEligibility $flowEligibility,
        private LaboratoryAppointmentCheckoutResolver $appointmentResolver,
        private LaboratoryAppointmentPaymentValidity $appointmentPaymentValidity,
    ) {}

    public function usesAppointmentFirstFlow(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $this->flowEligibility->usesAppointmentFirstFlow($customer, $brand);
    }

    public function hasConfirmedAppointment(Customer $customer, LaboratoryBrand $brand): bool
    {
        return $this->appointmentResolver->payableConfirmedAppointment($customer, $brand) !== null;
    }

    public function resolvePaymentBlockMessage(Customer $customer, LaboratoryBrand $brand): string
    {
        if (! $customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            return $this->paymentBlockedBeforeAppointmentMessage();
        }

        $cartId = $this->appointmentResolver->activeCartId($customer, $brand);
        $candidates = $customer->laboratoryAppointments()
            ->whereNotNull('confirmed_at')
            ->whereNull('laboratory_purchase_id')
            ->ofBrand($brand)
            ->orderByDesc('confirmed_at')
            ->get();

        $payable = $this->appointmentResolver->payableConfirmedAppointment($customer, $brand);
        if ($payable !== null) {
            return $this->paymentBlockedBeforeAppointmentMessage();
        }

        $confirmed = $candidates->first(
            fn ($appointment) => $this->appointmentPaymentValidity->isConfirmed($appointment)
                && ! $appointment->trashed()
                && ($cartId === null
                    || $appointment->cart_id === null
                    || (int) $appointment->cart_id === (int) $cartId),
        );

        if ($confirmed === null) {
            return $this->appointmentPaymentValidity->paymentBlockedUnavailableMessage();
        }

        if (! $this->appointmentPaymentValidity->hasScheduledDate($confirmed)) {
            return $this->appointmentPaymentValidity->paymentBlockedMissingScheduleMessage();
        }

        if ($this->appointmentPaymentValidity->isPastPaymentDeadline($confirmed)) {
            return $this->appointmentPaymentValidity->paymentDeadlineMessage();
        }

        return $this->appointmentPaymentValidity->paymentBlockedBeforeConfirmationMessage();
    }

    /**
     * @param  array<string, mixed>|null  $savedCheckout
     */
    public function resolveAccessibleStep(
        Customer $customer,
        LaboratoryBrand $brand,
        ?string $requestedStep,
        ?string $draftStep,
        bool $hasConfirmedAppointment,
        ?array $savedCheckout = null,
    ): CheckoutStepResolution {
        if (! $this->usesAppointmentFirstFlow($customer, $brand)) {
            return $this->resolveStandardFlowStep(
                $requestedStep,
                $draftStep,
                $customer->getHasLaboratoryCartItemRequiringAppointment($brand),
                $hasConfirmedAppointment,
            );
        }

        return $this->resolveAppointmentFirstStep(
            $customer,
            $brand,
            $requestedStep,
            $draftStep,
            $hasConfirmedAppointment,
            $savedCheckout,
        );
    }

    public function canSyncDraftStep(Customer $customer, LaboratoryBrand $brand, string $step): bool
    {
        if (! $this->usesAppointmentFirstFlow($customer, $brand)) {
            return in_array($step, [
                self::STEP_PATIENT,
                self::STEP_ADDRESS,
                self::STEP_PAYMENT,
            ], true);
        }

        if ($step === self::STEP_PAYMENT) {
            return $this->hasConfirmedAppointment($customer, $brand);
        }

        return in_array($step, [self::STEP_PATIENT, self::STEP_ADDRESS], true);
    }

    public function canInitiatePayment(Customer $customer, LaboratoryBrand $brand): bool
    {
        if (! $customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            return true;
        }

        if ($this->usesAppointmentFirstFlow($customer, $brand)) {
            return $this->hasConfirmedAppointment($customer, $brand);
        }

        return true;
    }

    public function nextDraftStepAfterSync(Customer $customer, LaboratoryBrand $brand, string $currentStep): string
    {
        $requiresAppointment = $customer->getHasLaboratoryCartItemRequiringAppointment($brand);

        if ($this->usesAppointmentFirstFlow($customer, $brand)) {
            return match ($currentStep) {
                self::STEP_PATIENT => self::STEP_ADDRESS,
                self::STEP_ADDRESS => self::STEP_APPOINTMENT,
                self::STEP_PAYMENT => self::STEP_PAYMENT,
                default => self::STEP_PATIENT,
            };
        }

        return match ($currentStep) {
            self::STEP_PATIENT => self::STEP_ADDRESS,
            self::STEP_ADDRESS => self::STEP_PAYMENT,
            self::STEP_PAYMENT => $requiresAppointment ? self::STEP_APPOINTMENT : self::STEP_CONFIRMATION,
            default => self::STEP_PATIENT,
        };
    }

    public function paymentBlockedBeforeAppointmentMessage(): string
    {
        return $this->appointmentPaymentValidity->paymentBlockedBeforeConfirmationMessage();
    }

    private function resolveStandardFlowStep(
        ?string $requestedStep,
        ?string $draftStep,
        bool $requiresAppointment,
        bool $hasConfirmedAppointment,
    ): CheckoutStepResolution {
        $step = $requestedStep ?? $draftStep ?? self::STEP_PATIENT;

        if (
            $step === self::STEP_CONFIRMATION
            && $requiresAppointment
            && ! $hasConfirmedAppointment
        ) {
            return new CheckoutStepResolution(self::STEP_APPOINTMENT);
        }

        return new CheckoutStepResolution($step);
    }

    /**
     * @param  array<string, mixed>|null  $savedCheckout
     */
    private function resolveAppointmentFirstStep(
        Customer $customer,
        LaboratoryBrand $brand,
        ?string $requestedStep,
        ?string $draftStep,
        bool $hasConfirmedAppointment,
        ?array $savedCheckout,
    ): CheckoutStepResolution {
        $step = $requestedStep ?? $draftStep ?? self::STEP_PATIENT;

        if ($step === self::STEP_CONFIRMATION) {
            $step = $hasConfirmedAppointment ? self::STEP_PAYMENT : self::STEP_APPOINTMENT;
        }

        if (
            in_array($step, [self::STEP_PAYMENT, self::STEP_CONFIRMATION], true)
            && ! $hasConfirmedAppointment
        ) {
            return new CheckoutStepResolution(
                self::STEP_APPOINTMENT,
                $this->resolvePaymentBlockMessage($customer, $brand),
                updateDraft: in_array($draftStep, [self::STEP_PAYMENT, self::STEP_CONFIRMATION], true),
            );
        }

        if (! in_array($step, [
            self::STEP_PATIENT,
            self::STEP_ADDRESS,
            self::STEP_APPOINTMENT,
            self::STEP_PAYMENT,
        ], true)) {
            $step = self::STEP_PATIENT;
        }

        $hasContact = filled($savedCheckout['contact_id'] ?? null);
        $hasAddress = filled($savedCheckout['address_id'] ?? null);

        if ($step === self::STEP_PAYMENT && (! $hasContact || ! $hasAddress)) {
            $step = ! $hasContact ? self::STEP_PATIENT : self::STEP_ADDRESS;
        } elseif ($step === self::STEP_APPOINTMENT && (! $hasContact || ! $hasAddress)) {
            $step = ! $hasContact ? self::STEP_PATIENT : self::STEP_ADDRESS;
        } elseif ($step === self::STEP_ADDRESS && ! $hasContact) {
            $step = self::STEP_PATIENT;
        }

        return new CheckoutStepResolution($step);
    }
}

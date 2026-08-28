<?php

namespace App\Actions\Laboratories;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Jobs\Carts\CheckAppointmentPendingJob;
use App\Models\Customer;
use App\Services\Carts\CartEventRecorder;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CreateLaboratoryAppointmentAction
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(Customer $customer, LaboratoryBrand $laboratoryBrand, ?array $clientContext = null)
    {
        $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand)
            ?? $customer->getPendingLaboratoryAppointment($laboratoryBrand);

        $this->syncMonitoringCartService->syncLaboratory($customer, $clientContext);
        $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $laboratoryBrand);

        if (! $cart && $customer->user_id && $customer->laboratoryCartItems()->ofBrand($laboratoryBrand)->exists()) {
            Log::warning('[CartTraceability] Laboratory appointment could not resolve monitoring cart', [
                'customer_id' => $customer->id,
                'user_id' => $customer->user_id,
                'brand' => $laboratoryBrand->value,
            ]);
        }

        $laboratoryAppointment ??= $customer->laboratoryAppointments()->create([
            'brand' => $laboratoryBrand,
        ]);

        if ($cart && Schema::hasColumn('laboratory_appointments', 'cart_id') && ! $laboratoryAppointment->cart_id) {
            $laboratoryAppointment->forceFill(['cart_id' => $cart->id])->save();
        }

        if ($cart) {
            $this->cartEventRecorder->recordOnce(
                $cart,
                CartEventType::AppointmentRequested,
                "laboratory_appointment:{$laboratoryAppointment->id}:requested",
                $this->withClientContext([
                    'laboratory_appointment_id' => $laboratoryAppointment->id,
                    'appointment_id' => $laboratoryAppointment->id,
                    'brand' => $laboratoryBrand->value,
                ], $clientContext),
                $laboratoryAppointment->created_at,
                'laboratory_checkout',
            );

            if ($laboratoryAppointment->confirmed_at === null) {
                CheckAppointmentPendingJob::dispatch((int) $laboratoryAppointment->id)
                    ->afterCommit()
                    ->delay(now()->addMinutes(max(1, (int) config('carts.appointment_pending_after_minutes', 5))));
            }
        }

        return $laboratoryAppointment;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function withClientContext(array $metadata, ?array $clientContext): array
    {
        if ($clientContext === null || $clientContext === []) {
            return $metadata;
        }

        return array_merge($metadata, ['client' => $clientContext]);
    }
}

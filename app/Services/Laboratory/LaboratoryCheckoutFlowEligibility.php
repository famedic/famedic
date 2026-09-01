<?php

namespace App\Services\Laboratory;

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fuente de verdad para decidir si un checkout de laboratorio debe usar el flujo
 * appointment_first. El conteo no reutiliza el segmento admin "recurrent".
 */
class LaboratoryCheckoutFlowEligibility
{
    public function countValidCompletedPurchases(Customer $customer): int
    {
        return $this->validCompletedPurchasesQuery($customer)->count();
    }

    public function usesAppointmentFirstFlow(Customer $customer, LaboratoryBrand $brand): bool
    {
        $requiresAppointment = $customer->getHasLaboratoryCartItemRequiringAppointment($brand);

        if (! $requiresAppointment) {
            return false;
        }

        return $this->countValidCompletedPurchases($customer) === 0;
    }

    public function validCompletedPurchasesQuery(Customer $customer): Builder
    {
        return LaboratoryPurchase::query()
            ->where('customer_id', $customer->id)
            ->whereNull('cancelled_at')
            ->whereHas('transactions', fn (Builder $query) => $this->applyCompletedCheckoutTransactionConstraints($query));
    }

    /**
     * Transacción que evidencia un checkout completado o pagado.
     *
     * Alineado con Transaction::isSuccessfulPayment(), incluyendo reembolsos posteriores
     * y excluyendo cobros fallidos, pendientes o simulados (details.simulated).
     */
    private function applyCompletedCheckoutTransactionConstraints(Builder $query): void
    {
        $query->whereNull('transactions.deleted_at')
            ->where(function (Builder $statusQuery) {
                $statusQuery->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', ['failed', 'declined', 'pending']);
            })
            ->where(function (Builder $evidenceQuery) {
                $evidenceQuery
                    ->whereIn('payment_status', [
                        'captured',
                        'completed',
                        'paid',
                        'success',
                        'succeeded',
                        'credit',
                        'refunded',
                    ])
                    ->orWhereIn('gateway_status', [
                        'completed',
                        'captured',
                        'paid',
                        'success',
                        'succeeded',
                    ])
                    ->orWhere(function (Builder $couponQuery) {
                        $couponQuery
                            ->where('payment_method', 'coupon_balance')
                            ->where('gateway_status', 'completed');
                    })
                    ->orWhere(function (Builder $referenceQuery) {
                        $referenceQuery
                            ->whereNotNull('reference_id')
                            ->where('reference_id', '!=', '');
                    });
            })
            ->where(function (Builder $simulatedQuery) {
                $simulatedQuery
                    ->whereNull('details->simulated')
                    ->orWhere('details->simulated', false);
            });
    }
}

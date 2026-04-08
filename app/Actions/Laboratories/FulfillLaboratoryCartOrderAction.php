<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use App\Notifications\FewDaysLeftToRequestInvoice;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;

class FulfillLaboratoryCartOrderAction
{
    public function __construct(
        private CreateGDAQuotationAction $createGDAQuotationAction,
        private SyncMonitoringCartService $syncMonitoringCartService,
    ) {
    }

    /**
     * Crea el pedido de laboratorio, cotización GDA, limpia carrito y notifica.
     * Debe llamarse cuando el cobro ya fue autorizado/capturado (tarjeta, PayPal, etc.).
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Address $address,
        Contact $contact,
        Transaction $transaction,
        ?LaboratoryAppointment $laboratoryAppointment,
        Collection $laboratoryCartItems,
        string $gdaBrandValue,
    ): LaboratoryPurchase {
        DB::beginTransaction();

        try {
            $laboratoryPurchase = $this->createLaboratoryPurchase(
                $customer,
                $laboratoryBrand,
                $contact,
                $address,
                $laboratoryCartItems
            );

            $laboratoryPurchase->transactions()->attach($transaction);

            if (
                $laboratoryAppointment
                && $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)
            ) {
                $laboratoryAppointment->laboratory_purchase_id = $laboratoryPurchase->id;
                $laboratoryAppointment->save();
            }

            logger('=== GDA BRAND DEBUG (FulfillLaboratoryCartOrderAction) ===');
            logger('LaboratoryBrand Enum value: ' . $laboratoryBrand->value);
            logger('GDA brand value: ' . $gdaBrandValue);

            if (app()->environment('local')) {
                $gdaQuotation = ['id' => rand(100000, 999999)];
            } else {
                $gdaQuotation = ($this->createGDAQuotationAction)(
                    $customer,
                    $address,
                    $contact,
                    $gdaBrandValue,
                    $laboratoryCartItems,
                    $laboratoryPurchase->id
                );
            }

            $laboratoryPurchase->update([
                'gda_order_id' => $gdaQuotation['id'],
                'gda_consecutivo' => $gdaQuotation['infogda_consecutivo'] ?? null,
                'gda_acuse' => $gdaQuotation['gda_acuse'] ?? null,
                'gda_response' => $gdaQuotation['gda_response'] ?? null,
                'gda_code_http' => $gdaQuotation['gda_code_http'] ?? null,
                'gda_mensaje' => $gdaQuotation['gda_mensaje'] ?? null,
                'gda_description' => $gdaQuotation['gda_description'] ?? null,
                'pdf_base64' => $gdaQuotation['pdf_base64'] ?? null,
            ]);

            $this->syncMonitoringCartService->markLaboratoryCartCompleted($customer);
            $this->clearCart($customer);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        $laboratoryPurchase->customer->user->notify(new LaboratoryPurchaseCreated($laboratoryPurchase));

        $this->checkAndSendInvoiceDeadlineNotification($laboratoryPurchase);

        return $laboratoryPurchase;
    }

    private function clearCart(Customer $customer): void
    {
        $customer->laboratoryCartItems()->delete();
    }

    private function createLaboratoryPurchase(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Contact $contact,
        Address $address,
        Collection $laboratoryCartItems
    ): LaboratoryPurchase {
        $totalCents = $laboratoryCartItems->sum(function ($laboratoryCartItem) {
            return $laboratoryCartItem->laboratoryTest->famedic_price_cents;
        });

        $laboratoryPurchase = $customer->laboratoryPurchases()->save(
            new LaboratoryPurchase([
                'gda_order_id' => 0,
                'brand' => $laboratoryBrand->value,
                'name' => $contact->name,
                'paternal_lastname' => $contact->paternal_lastname,
                'maternal_lastname' => $contact->maternal_lastname,
                'phone' => str_replace(' ', '', (new PhoneNumber($contact->phone, $contact->phone_country))->formatNational()),
                'phone_country' => $contact->phone_country,
                'birth_date' => $contact->birth_date,
                'gender' => $contact->gender,
                'street' => $address->street,
                'number' => $address->number,
                'neighborhood' => $address->neighborhood,
                'state' => $address->state,
                'city' => $address->city,
                'zipcode' => $address->zipcode,
                'additional_references' => $address->additional_references,
                'total_cents' => $totalCents,
            ])
        );

        foreach ($laboratoryCartItems as $laboratoryCartItem) {
            $laboratoryPurchase->laboratoryPurchaseItems()->save(
                new LaboratoryPurchaseItem([
                    'name' => $laboratoryCartItem->laboratoryTest->name,
                    'gda_id' => $laboratoryCartItem->laboratoryTest->gda_id,
                    'indications' => $laboratoryCartItem->laboratoryTest->indications,
                    'price_cents' => $laboratoryCartItem->laboratoryTest->famedic_price_cents,
                ])
            );
        }

        return $laboratoryPurchase;
    }

    private function checkAndSendInvoiceDeadlineNotification(LaboratoryPurchase $laboratoryPurchase): void
    {
        if (!$laboratoryPurchase->customer->taxProfiles()->exists()) {
            return;
        }

        $lastDayOfPurchaseMonth = $laboratoryPurchase->created_at->endOfMonth();
        $daysLeft = now()->diffInDays($lastDayOfPurchaseMonth);

        if ($daysLeft <= 7) {
            $laboratoryPurchase->customer->user->notify(
                new FewDaysLeftToRequestInvoice($laboratoryPurchase, $daysLeft)
            );
        }
    }
}

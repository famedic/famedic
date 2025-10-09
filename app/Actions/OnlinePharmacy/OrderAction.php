<?php

namespace App\Actions\OnlinePharmacy;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Stripe\ChargeStripePaymentMethodAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\OnlinePharmacyPurchase;
use App\Models\OnlinePharmacyPurchaseItem;
use App\Models\Transaction;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Propaganistas\LaravelPhone\PhoneNumber;

class OrderAction
{
    private FetchAuthenticationTokenAction $fetchAuthenticationTokenAction;
    private FetchCalculateAction $fetchCalculateAction;
    private ChargeStripePaymentMethodAction $chargeStripePaymentMethodAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private RefundTransactionAction $refundTransactionAction;

    private Collection $onlinePharmacyCartItems;

    public function __construct(
        FetchAuthenticationTokenAction $fetchAuthenticationTokenAction,
        FetchCalculateAction $fetchCalculateAction,
        ChargeStripePaymentMethodAction $chargeStripePaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,

        RefundTransactionAction $refundTransactionAction
    ) {
        $this->fetchAuthenticationTokenAction = $fetchAuthenticationTokenAction;
        $this->fetchCalculateAction = $fetchCalculateAction;
        $this->chargeStripePaymentMethodAction = $chargeStripePaymentMethodAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->refundTransactionAction = $refundTransactionAction;
    }

    public function __invoke(
        Customer $customer,
        Address $address,
        Contact $contact,
        string $paymentMethod,
        int $totalCents
    ): OnlinePharmacyPurchase {

        $this->onlinePharmacyCartItems = $customer->onlinePharmacyCartItems;

        $details = $this->buildDetails();
        $calculatedTotalCents = intval(floatval(($this->fetchCalculateAction)($address->zipcode, $details)['total']) * 100);

        if ($totalCents != $calculatedTotalCents) {
            throw new UnmatchingTotalPriceException();
        }

        $transaction = null;
        $onlinePharmacyPurchase = null;

        try {
            DB::beginTransaction();

            $transaction = $this->chargeAndCreateTransaction($totalCents, $paymentMethod, $customer);

            DB::commit();

            DB::beginTransaction();

            $response = $this->sendVitauRequest($customer, $address, $contact, $details);

            $onlinePharmacyPurchase = $this->createOnlinePharmacyPurchase($customer, $contact, $address,  $response->json());

            $onlinePharmacyPurchase->transactions()->attach($transaction);

            $vitauOrderId = $response->json()['id'];

            $onlinePharmacyPurchase->update([
                'vitau_order_id' => $vitauOrderId,
            ]);

            $this->clearCart($customer);

            DB::commit();
        } catch (\Throwable $th) {
            logger($th);
            DB::rollBack();

            if ($transaction) {
                ($this->refundTransactionAction)($transaction);
            }
        }

        return $onlinePharmacyPurchase;
    }

    private function buildDetails()
    {
        $details = [];

        foreach ($this->onlinePharmacyCartItems as $onlinePharmacyCartItem) {
            $details[] = [
                'product' => $onlinePharmacyCartItem->vitau_product_id,
                'quantity' => $onlinePharmacyCartItem->quantity,
            ];
        }

        return $details;
    }

    public function clearCart(Customer $customer)
    {
        $customer->onlinePharmacyCartItems()->delete();
    }

    private function chargeAndCreateTransaction(int $amountCents, string $paymentMethod, Customer $customer): Transaction
    {
        if ($paymentMethod === 'odessa') {
            return ($this->chargeOdessaAction)($customer->customerable, $amountCents);
        }

        return ($this->chargeStripePaymentMethodAction)(
            $customer,
            $amountCents,
            $paymentMethod
        );
    }

    private function createOnlinePharmacyPurchase(Customer $customer, Contact $contact, Address $address, array $order): OnlinePharmacyPurchase
    {
        $onlinePharmacyPurchase = $customer->onlinePharmacyPurchases()->save(
            new OnlinePharmacyPurchase([
                'vitau_order_id' => $order['id'],
                'name' => $contact->name,
                'paternal_lastname' => $contact->paternal_lastname,
                'maternal_lastname' => $contact->maternal_lastname,
                'phone' => str_replace(' ', '', (new PhoneNumber($contact->phone, $contact->phone_country))->formatNational()),
                'phone_country' => $contact->phone_country,
                'street' => $address->street,
                'number' => $address->number,
                'neighborhood' => $address->neighborhood,
                'state' => $address->state,
                'city' => $address->city,
                'zipcode' => $address->zipcode,
                'additional_references' => $address->additional_references,
                'subtotal_cents' => intval(floatval($order['subtotal']) * 100),
                'shipping_price_cents' => intval(floatval($order['shipping_price']) * 100),
                'tax_cents' => intval(floatval($order['iva']) * 100),
                'discount_cents' => intval(floatval($order['discount']) * 100),
                'total_cents' => intval(floatval($order['total']) * 100),
                'expected_delivery_date' => Carbon::parse($order['expected_delivery_date']),
            ])
        );

        foreach ($order['details'] as $item) {
            $onlinePharmacyPurchase->onlinePharmacyPurchaseItems()->save(
                new OnlinePharmacyPurchaseItem([
                    'name' => $item['product']['base']['name'],
                    'vitau_product_id' => $item['product']['id'],
                    'presentation' => $item['product']['presentation'],
                    'quantity' => $item['quantity'],
                    'price_cents' => intval(floatval($item['price']) * 100),
                    'subtotal_cents' => intval(floatval($item['subtotal']) * 100),
                    'discount_cents' => intval(floatval($item['discount']) * 100),
                    'tax_cents' => intval(floatval($item['iva']) * 100),
                    'total_cents' => intval(floatval($item['total']) * 100),
                ])
            );
        }

        return $onlinePharmacyPurchase;
    }

    public function sendVitauRequest(Customer $customer, Address $address, Contact $contact, array $details)
    {
        $url = config('services.vitau.url') . 'orders/resources/';

        $response =  Http::withToken(($this->fetchAuthenticationTokenAction)())
            ->withHeaders([
                'x-api-key' => config('services.vitau.key'),
            ])->post($url, [
                "user" => [
                    "email" => $customer->user->email,
                    "first_name" => $contact->name,
                    "last_name" => $contact->paternal_lastname,
                    "second_last_name" => $contact->maternal_lastname
                ],
                "order" => [
                    "shipping" => [
                        "street" => $address->street,
                        "exterior_number" => $address->number,
                        "neighborhood" => $address->neighborhood,
                        "city" => $address->city,
                        "state" => $address->state,
                        "country" => "México",
                        "zipcode" => $address->zipcode,
                        "phone" => $contact->phone->formatE164(),
                        "additional_info" => $address->additional_references ?? ""
                    ],
                    "payment_method" => config('services.vitau.payment_method'),
                    "payment_status" => "credit",
                    "details" => $details
                ]
            ]);

        if ($response->failed()) {
            logger($response);
            throw new Exception('Error while processing order with Vitau');
        }

        return $response;
    }
}

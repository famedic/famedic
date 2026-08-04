<?php

use App\Exports\Sheets\LaboratoryPurchasesSheet;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EfevooPayCommissionCalculator;
use Illuminate\Database\Eloquent\Collection;

test('hoja de pedidos exporta comisión total EfevooPay calculada', function () {
    config([
        'efevoopay.commission.rate_percent' => 2.99,
        'efevoopay.commission.vat_rate_percent' => 16,
    ]);

    $user = User::factory()->make(['name' => 'Paciente', 'paternal_lastname' => 'Test']);
    $customer = Customer::factory()->make();
    $customer->setRelation('user', $user);

    $transaction = new Transaction([
        'payment_method' => 'efevoopay',
        'gateway' => 'efevoopay',
        'transaction_amount_cents' => 26900,
        'reference_id' => '12345',
        'details' => ['commission_cents' => 0],
    ]);

    $purchase = LaboratoryPurchase::factory()->make([
        'gda_order_id' => 'GDA-TEST',
        'total_cents' => 26900,
    ]);
    $purchase->setRelation('customer', $customer);
    $purchase->setRelation('transactions', new Collection([$transaction]));
    $purchase->setRelation('laboratoryPurchaseItems', new Collection());
    $purchase->setRelation('invoice', null);
    $purchase->setRelation('invoiceRequest', null);
    $purchase->setRelation('laboratoryAppointment', null);
    $purchase->setRelation('vendorPayments', new Collection());
    $purchase->laboratory_purchase_items_count = 1;

    $sheet = new LaboratoryPurchasesSheet;
    $row = $sheet->map($purchase);

    expect($sheet->headings()[7])->toBe('Comisión total EfevooPay');
    expect($row[7])->toBe(numberCents(
        EfevooPayCommissionCalculator::calculate(26900)['total_cents']
    ));
    expect($row[9])->toBe('EfevooPay');
});
